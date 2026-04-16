<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\ConfiguracionConsultora;
use App\Models\EmpresaConsultora;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmpresaConsultoraController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $q = EmpresaConsultora::query()->with('usuario');

        if ($s = $request->get('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('razon_social', 'like', "%{$s}%")
                    ->orWhere('nit', 'like', "%{$s}%")
                    ->orWhere('correo_principal', 'like', "%{$s}%");
            });
        }

        if ($estado = $request->get('estado')) {
            $q->where('estado', $estado);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $paginator = $q->orderBy($request->get('sort_by', 'creado_en'), $request->get('sort_direction', 'desc'))
            ->paginate($perPage);

        $items = collect($paginator->items())->map(function (EmpresaConsultora $e) {
            $u = $e->usuario;

            return [
                'id' => $e->id,
                'razon_social' => $e->razon_social,
                'nit' => $e->nit,
                'correo' => $e->correo_principal,
                'correo_acceso' => $u?->correo,
                'estado' => $e->estado,
                'acceso_habilitado' => $u && $u->estado !== 'inactivo',
                'usuario_estado' => $u?->estado,
                'debe_cambiar_contrasena' => (bool) ($u?->debe_cambiar_contrasena),
            ];
        })->all();

        return $this->ok([
            'data' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $e = EmpresaConsultora::query()->with('usuario', 'configuracion')->find($id);
        if (! $e) {
            return $this->fail('No encontrada', 404);
        }

        return $this->ok($e);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $empresa = EmpresaConsultora::query()->with('usuario')->find($id);
        if (! $empresa) {
            return $this->fail('No encontrada', 404);
        }

        $u = $empresa->usuario;

        $data = $request->validate([
            'razon_social' => ['required', 'string', 'max:200'],
            'nit' => ['required', 'string', 'max:30'],
            'representante_nombre' => ['required', 'string', 'max:200'],
            'representante_ci' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'correo_acceso' => ['required', 'email', 'max:150'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'nombre_comercial' => ['nullable', 'string', 'max:150'],
            'direccion' => ['nullable', 'string'],
        ]);

        if (EmpresaConsultora::query()->where('nit', $data['nit'])->where('id', '!=', $id)->exists()) {
            return $this->fail('El NIT ya está registrado.', 422);
        }

        if ($u && Usuario::query()->where('correo', $data['correo_acceso'])->where('id', '!=', $u->id)->exists()) {
            return $this->fail('El correo ya está registrado.', 422);
        }

        [$nombres, $apellidos] = $this->splitNombre($data['representante_nombre']);

        DB::transaction(function () use ($empresa, $u, $data, $nombres, $apellidos) {
            $empresa->forceFill([
                'razon_social' => $data['razon_social'],
                'nit' => $data['nit'],
                'representante_nombres' => $nombres,
                'representante_apellidos' => $apellidos,
                'representante_ci' => $data['representante_ci'] ?? null,
                'telefono' => $data['telefono'] ?? null,
                'correo_principal' => $data['correo_acceso'],
                'ciudad' => $data['ciudad'] ?? null,
                'departamento' => $data['departamento'] ?? null,
                'nombre_comercial' => $data['nombre_comercial'] ?? null,
                'direccion' => $data['direccion'] ?? null,
            ])->save();

            if ($u) {
                $u->forceFill(['correo' => $data['correo_acceso']])->save();
            }
        });

        return $this->ok($empresa->fresh()->load('usuario', 'configuracion'), 'Consultora actualizada');
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $request->user()->administrador;
        if (! $admin) {
            return $this->fail('Perfil administrador no encontrado.', 403);
        }

        $data = $request->validate([
            'razon_social' => ['required', 'string', 'max:200'],
            'nit' => ['required', 'string', 'max:30'],
            'representante_nombre' => ['required', 'string', 'max:200'],
            'representante_ci' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'correo_acceso' => ['required', 'email', 'max:150'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'acceso_habilitado' => ['sometimes', 'boolean'],
        ]);

        $accesoHabilitado = $data['acceso_habilitado'] ?? true;

        if (EmpresaConsultora::query()->where('nit', $data['nit'])->exists()) {
            return $this->fail('El NIT ya está registrado.', 422);
        }

        if (Usuario::query()->where('correo', $data['correo_acceso'])->exists()) {
            return $this->fail('El correo ya está registrado.', 422);
        }

        [$nombres, $apellidos] = $this->splitNombre($data['representante_nombre']);

        $baseUser = Str::slug(Str::before($data['correo_acceso'], '@')) ?: 'consultora';
        $nombreUsuario = $baseUser;
        $i = 0;
        while (Usuario::query()->where('nombre_usuario', $nombreUsuario)->exists()) {
            $nombreUsuario = $baseUser.++$i;
        }

        $empresa = DB::transaction(function () use ($data, $admin, $nombres, $apellidos, $nombreUsuario, $accesoHabilitado) {
            $usuario = Usuario::create([
                'nombre_usuario' => $nombreUsuario,
                'correo' => $data['correo_acceso'],
                'contrasena_hash' => Hash::make($data['password']),
                'tipo' => 'consultora',
                'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
                'verificado' => $accesoHabilitado,
                'debe_cambiar_contrasena' => $accesoHabilitado,
                'token_activacion' => null,
                'token_activacion_exp' => null,
            ]);

            $empresa = EmpresaConsultora::create([
                'usuario_id' => $usuario->id,
                'registrada_por' => $admin->id,
                'razon_social' => $data['razon_social'],
                'nit' => $data['nit'],
                'representante_nombres' => $nombres,
                'representante_apellidos' => $apellidos,
                'representante_ci' => $data['representante_ci'] ?? null,
                'correo_principal' => $data['correo_acceso'],
                'telefono' => $data['telefono'] ?? null,
                'ciudad' => $data['ciudad'] ?? null,
                'departamento' => $data['departamento'] ?? null,
                'estado' => $accesoHabilitado ? 'activo_sin_config' : 'pendiente_activacion',
            ]);

            ConfiguracionConsultora::query()->firstOrCreate(['consultora_id' => $empresa->id]);

            return $empresa;
        });

        return $this->ok([
            'empresa' => $empresa->load('usuario'),
            'nota' => $accesoHabilitado
                ? 'La consultora puede iniciar sesión y deberá cambiar la contraseña en el primer acceso.'
                : 'Acceso al portal deshabilitado. Actívalo cuando corresponda.',
        ], 'Consultora registrada', 201);
    }

    public function updateAccesoUsuario(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'acceso_habilitado' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $empresa = EmpresaConsultora::query()->with('usuario')->find($id);
        if (! $empresa || ! $empresa->usuario) {
            return $this->fail('No encontrada', 404);
        }

        $u = $empresa->usuario;

        DB::transaction(function () use ($u, $empresa, $data) {
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

                if (in_array($empresa->estado, ['pendiente_activacion'], true)) {
                    $empresa->update(['estado' => 'activo_sin_config']);
                }
            } else {
                $u->forceFill([
                    'estado' => 'inactivo',
                    'debe_cambiar_contrasena' => false,
                ])->save();
            }
        });

        return $this->ok([
            'empresa' => $empresa->fresh()->load('usuario'),
            'acceso_habilitado' => $u->fresh()->estado !== 'inactivo',
        ], 'Acceso actualizado');
    }

    public function reenviarActivacion(Request $request, int $id): JsonResponse
    {
        $empresa = EmpresaConsultora::query()->with('usuario')->find($id);
        if (! $empresa || ! $empresa->usuario) {
            return $this->fail('No encontrada', 404);
        }

        $u = $empresa->usuario;
        if ($u->estado !== 'pendiente_activacion') {
            return $this->fail('La consultora ya no está pendiente de activación.', 422);
        }

        $plainToken = Str::random(48);
        $u->forceFill([
            'token_activacion' => hash('sha256', $plainToken),
            'token_activacion_exp' => now()->addHours(48),
        ])->save();

        return $this->ok([
            'token_demo' => $plainToken,
            'url_ejemplo' => url('/activar-cuenta?token='.$plainToken),
        ], 'Token regenerado');
    }

    private function splitNombre(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return ['', ''];
        }
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $apellidos = array_pop($parts);

        return [implode(' ', $parts), $apellidos];
    }
}
