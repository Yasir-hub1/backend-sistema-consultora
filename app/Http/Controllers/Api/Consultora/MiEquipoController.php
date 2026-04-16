<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\Colaborador;
use App\Models\ColaboradorPermiso;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MiEquipoController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $consultoraId = $e->id;

        $q = Colaborador::query()
            ->where('consultora_id', $consultoraId)
            ->with('usuario')
            ->orderBy('id');

        if ($s = $request->get('search')) {
            $s = trim((string) $s);
            if ($s !== '') {
                $q->where(function ($w) use ($s) {
                    $like = '%'.$s.'%';
                    $w->where('nombres', 'like', $like)
                        ->orWhere('apellidos', 'like', $like)
                        ->orWhere('ci', 'like', $like)
                        ->orWhere('telefono', 'like', $like)
                        ->orWhere('cargo', 'like', $like)
                        ->orWhereHas('usuario', function ($uq) use ($like) {
                            $uq->where('correo', 'like', $like)
                                ->orWhere('nombre_usuario', 'like', $like);
                        });
                });
            }
        }

        $rows = $q->paginate(min((int) $request->get('per_page', 15), 100));

        $enEquipo = Colaborador::query()->where('consultora_id', $consultoraId)->count();
        $conPortalActivo = Colaborador::query()
            ->where('consultora_id', $consultoraId)
            ->whereHas('usuario', fn ($uq) => $uq->where('estado', '!=', 'inactivo'))
            ->count();

        $items = collect($rows->items())->map(function (Colaborador $c) {
            $row = $c->toArray();
            $u = $c->usuario;
            $row['acceso_habilitado'] = $u && $u->estado !== 'inactivo';
            $row['usuario_estado'] = $u?->estado;
            $row['debe_cambiar_contrasena'] = (bool) ($u?->debe_cambiar_contrasena);

            return $row;
        })->all();

        return $this->ok([
            'data' => $items,
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => $rows->total(),
            'stats' => [
                'en_equipo' => $enEquipo,
                'con_portal_activo' => $conPortalActivo,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $data = $request->validate([
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'correo' => ['required', 'email', 'max:150'],
            'cargo' => ['required', 'string', 'max:64'],
            'fecha_ingreso' => ['nullable', 'date'],
            'password' => ['required', 'string', 'min:8'],
            'acceso_habilitado' => ['sometimes', 'boolean'],
        ]);

        $accesoHabilitado = $data['acceso_habilitado'] ?? true;

        if (Usuario::query()->where('correo', $data['correo'])->exists()) {
            return $this->fail('El correo ya existe.', 422);
        }

        $nuBase = Str::slug(Str::before($data['correo'], '@')) ?: 'colab';
        $nu = $nuBase;
        $i = 0;
        while (Usuario::query()->where('nombre_usuario', $nu)->exists()) {
            $nu = $nuBase.++$i;
        }

        $usuario = Usuario::create([
            'nombre_usuario' => $nu,
            'correo' => $data['correo'],
            'contrasena_hash' => Hash::make($data['password']),
            'tipo' => 'colaborador',
            'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
            'verificado' => $accesoHabilitado,
            'debe_cambiar_contrasena' => $accesoHabilitado,
        ]);

        $col = Colaborador::create([
            'consultora_id' => $e->id,
            'usuario_id' => $usuario->id,
            'nombres' => $data['nombres'],
            'apellidos' => $data['apellidos'],
            'ci' => $data['ci'],
            'telefono' => $data['telefono'] ?? null,
            'cargo' => $data['cargo'],
            'fecha_ingreso' => $data['fecha_ingreso'] ?? now(),
            'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
        ]);

        $this->seedPermisos($col, $data['cargo'], $e->id);

        return $this->ok([
            'colaborador' => $col->load('usuario'),
            'nombre_usuario' => $nu,
            'nota' => $accesoHabilitado
                ? 'El colaborador debe cambiar la contraseña en el primer acceso.'
                : 'Acceso deshabilitado; puede habilitarse desde el listado.',
        ], 'Colaborador creado', 201);
    }

    public function updateAcceso(Request $request, int $id): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $data = $request->validate([
            'acceso_habilitado' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $col = Colaborador::query()->where('consultora_id', $e->id)->whereKey($id)->first();
        if (! $col || ! $col->usuario) {
            return $this->fail('Colaborador no encontrado', 404);
        }

        $u = $col->usuario;

        if ($data['acceso_habilitado']) {
            $u->forceFill([
                'estado' => 'activo',
                'verificado' => true,
            ]);
            if (! empty($data['password'])) {
                $u->contrasena_hash = Hash::make($data['password']);
                $u->debe_cambiar_contrasena = true;
            }
            $u->save();
            if ($col->estado === 'inactivo') {
                $col->update(['estado' => 'activo']);
            }
        } else {
            $u->forceFill([
                'estado' => 'inactivo',
                'debe_cambiar_contrasena' => false,
            ])->save();
            $col->update(['estado' => 'inactivo']);
        }

        return $this->ok([
            'colaborador' => $col->fresh()->load('usuario'),
            'acceso_habilitado' => $u->fresh()->estado !== 'inactivo',
        ], 'Acceso actualizado');
    }

    public function updatePermisos(Request $request, int $colaboradorId): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $col = Colaborador::query()->where('consultora_id', $e->id)->whereKey($colaboradorId)->first();
        if (! $col) {
            return $this->fail('Colaborador no encontrado', 404);
        }

        $payload = $request->validate([
            'permisos' => ['required', 'array'],
            'permisos.*.modulo' => ['required', 'string'],
            'permisos.*.puede_ver' => ['sometimes', 'boolean'],
            'permisos.*.puede_registrar_personal' => ['sometimes', 'boolean'],
            'permisos.*.puede_editar_personal' => ['sometimes', 'boolean'],
            'permisos.*.puede_subir_documentos' => ['sometimes', 'boolean'],
            'permisos.*.puede_eliminar_documentos' => ['sometimes', 'boolean'],
            'permisos.*.puede_gestionar_modulo' => ['sometimes', 'boolean'],
            'permisos.*.puede_exportar_reportes' => ['sometimes', 'boolean'],
            'permisos.*.puede_invitar_empresa' => ['sometimes', 'boolean'],
        ]);

        foreach ($payload['permisos'] as $row) {
            ColaboradorPermiso::query()->updateOrCreate(
                ['colaborador_id' => $col->id, 'modulo' => $row['modulo']],
                [
                    'puede_ver' => $row['puede_ver'] ?? false,
                    'puede_registrar_personal' => $row['puede_registrar_personal'] ?? false,
                    'puede_editar_personal' => $row['puede_editar_personal'] ?? false,
                    'puede_subir_documentos' => $row['puede_subir_documentos'] ?? false,
                    'puede_eliminar_documentos' => $row['puede_eliminar_documentos'] ?? false,
                    'puede_gestionar_modulo' => $row['puede_gestionar_modulo'] ?? false,
                    'puede_exportar_reportes' => $row['puede_exportar_reportes'] ?? false,
                    'puede_invitar_empresa' => $row['puede_invitar_empresa'] ?? false,
                    'configurado_por' => $e->id,
                ]
            );
        }

        return $this->ok($col->permisosPorModulo()->get());
    }

    private function seedPermisos(Colaborador $col, string $cargo, int $consultoraId): void
    {
        $modulos = ['afp', 'caja', 'ministerio'];
        $allOn = fn () => [
            'puede_ver' => true,
            'puede_registrar_personal' => true,
            'puede_editar_personal' => true,
            'puede_subir_documentos' => true,
            'puede_eliminar_documentos' => true,
            'puede_gestionar_modulo' => true,
            'puede_exportar_reportes' => true,
            'puede_invitar_empresa' => $cargo === 'coordinador_general',
        ];

        $asistente = fn () => [
            'puede_ver' => true,
            'puede_registrar_personal' => false,
            'puede_editar_personal' => false,
            'puede_subir_documentos' => true,
            'puede_eliminar_documentos' => false,
            'puede_gestionar_modulo' => false,
            'puede_exportar_reportes' => false,
            'puede_invitar_empresa' => false,
        ];

        foreach ($modulos as $m) {
            $base = match ($cargo) {
                'coordinador_general' => $allOn(),
                'asistente' => $asistente(),
                default => [
                    'puede_ver' => true,
                    'puede_registrar_personal' => false,
                    'puede_editar_personal' => false,
                    'puede_subir_documentos' => true,
                    'puede_eliminar_documentos' => false,
                    'puede_gestionar_modulo' => false,
                    'puede_exportar_reportes' => false,
                    'puede_invitar_empresa' => false,
                ],
            };

            if (str_starts_with($cargo, 'analista_')) {
                $mod = str_replace('analista_', '', $cargo);
                $base['puede_gestionar_modulo'] = $mod === $m;
                $base['puede_subir_documentos'] = $mod === $m;
            }

            ColaboradorPermiso::create(array_merge([
                'colaborador_id' => $col->id,
                'modulo' => $m,
                'configurado_por' => $consultoraId,
            ], $base));
        }
    }
}
