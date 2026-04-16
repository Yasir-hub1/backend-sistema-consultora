<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaCliente;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmpresaClienteController extends ApiController
{
    private function consultoraId(Request $request): ?int
    {
        return $request->user()->empresaConsultoraTitular?->id;
    }

    public function index(Request $request): JsonResponse
    {
        $cid = $this->consultoraId($request);
        if (! $cid) {
            return $this->fail('Sin consultora.', 403);
        }

        $q = EmpresaCliente::query()->where('consultora_id', $cid);
        if ($s = $request->get('search')) {
            $s = trim((string) $s);
            if ($s !== '') {
                $like = '%'.$s.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('nombre', 'like', $like)
                        ->orWhere('nit', 'like', $like)
                        ->orWhere('razon_social', 'like', $like)
                        ->orWhere('correo_empresa', 'like', $like)
                        ->orWhere('ciudad', 'like', $like);
                });
            }
        }

        $p = $q->with('usuario')->orderBy('id', 'desc')->paginate(min((int) $request->get('per_page', 15), 100));

        $enCartera = EmpresaCliente::query()->where('consultora_id', $cid)->count();
        $conPortalActivo = EmpresaCliente::query()
            ->where('consultora_id', $cid)
            ->whereHas('usuario', fn ($uq) => $uq->where('estado', '!=', 'inactivo'))
            ->count();

        $items = collect($p->items())->map(function (EmpresaCliente $emp) {
            $row = $emp->toArray();
            $u = $emp->usuario;
            $row['acceso_portal_habilitado'] = $emp->usuario_id && $u && $u->estado !== 'inactivo';
            $row['usuario_estado'] = $u?->estado;

            return $row;
        })->all();

        return $this->ok([
            'data' => $items,
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
            'stats' => [
                'en_cartera' => $enCartera,
                'con_portal_activo' => $conPortalActivo,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $cid = $this->consultoraId($request);
        if (! $cid) {
            return $this->fail('Sin consultora.', 403);
        }

        $emp = EmpresaCliente::query()->where('consultora_id', $cid)->with(['colaboradores', 'usuario'])->find($id);
        if (! $emp) {
            return $this->fail('No encontrada', 404);
        }

        return $this->ok($emp);
    }

    public function store(Request $request): JsonResponse
    {
        $cid = $this->consultoraId($request);
        if (! $cid) {
            return $this->fail('Sin consultora.', 403);
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:200'],
            'nit' => ['required', 'string', 'max:30'],
            'razon_social' => ['nullable', 'string', 'max:200'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'string'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'correo_contacto' => ['nullable', 'email', 'max:150'],
            'correo_empresa' => ['nullable', 'email', 'max:150'],
            'representante_nombre' => ['nullable', 'string', 'max:200'],
            'representante_ci' => ['nullable', 'string', 'max:20'],
            'rep_legal_nombres' => ['nullable', 'string', 'max:100'],
            'rep_legal_apellidos' => ['nullable', 'string', 'max:100'],
            'rep_legal_ci' => ['nullable', 'string', 'max:20'],
            'actividad_economica' => ['nullable', 'string', 'max:200'],
            'matricula_comercio' => ['nullable', 'string', 'max:50'],
        ]);

        if (EmpresaCliente::query()->where('consultora_id', $cid)->where('nit', $data['nit'])->exists()) {
            return $this->fail('NIT ya registrado para esta consultora.', 422);
        }

        $colab = $request->user()->colaborador;
        $rep = $this->splitNombre($data['representante_nombre'] ?? '');

        $emp = EmpresaCliente::create([
            'consultora_id' => $cid,
            'registrada_por' => $colab?->id,
            'nombre' => $data['nombre'],
            'nit' => $data['nit'],
            'razon_social' => $data['razon_social'] ?? $data['nombre'],
            'ciudad' => $data['ciudad'] ?? null,
            'departamento' => $data['departamento'] ?? null,
            'direccion' => $data['direccion'] ?? null,
            'telefono' => $data['telefono'] ?? null,
            'correo_empresa' => $data['correo_empresa'] ?? $data['correo_contacto'] ?? null,
            'actividad_economica' => $data['actividad_economica'] ?? null,
            'matricula_comercio' => $data['matricula_comercio'] ?? null,
            'rep_legal_nombres' => $data['rep_legal_nombres'] ?? $rep[0],
            'rep_legal_apellidos' => $data['rep_legal_apellidos'] ?? $rep[1],
            'rep_legal_ci' => $data['rep_legal_ci'] ?? $data['representante_ci'] ?? null,
            'estado' => 'activo',
        ]);

        return $this->ok($emp, 'Empresa cliente creada', 201);
    }

    public function generarAcceso(Request $request, int $id): JsonResponse
    {
        $cid = $this->consultoraId($request);
        if (! $cid) {
            return $this->fail('Sin consultora.', 403);
        }

        $emp = EmpresaCliente::query()->where('consultora_id', $cid)->find($id);
        if (! $emp) {
            return $this->fail('No encontrada', 404);
        }

        $data = $request->validate([
            'correo' => ['nullable', 'email', 'max:150'],
            'password' => ['required', 'string', 'min:8'],
            'acceso_habilitado' => ['sometimes', 'boolean'],
        ]);

        $correo = $data['correo'] ?? $emp->correo_empresa;
        if (! $correo) {
            return $this->fail('Indica un correo.', 422);
        }

        $accesoHabilitado = $data['acceso_habilitado'] ?? true;

        $nuBase = 'emp_'.Str::slug(Str::limit($emp->nombre, 20, '')).'_'.Str::lower(Str::random(4));
        $nuBase = Str::substr(preg_replace('/[^a-z0-9_]/', '_', $nuBase), 0, 78);

        $u = $emp->usuario_id ? Usuario::query()->find($emp->usuario_id) : null;

        if ($u) {
            $u->forceFill([
                'correo' => $correo,
                'contrasena_hash' => Hash::make($data['password']),
                'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
                'verificado' => $accesoHabilitado,
                'debe_cambiar_contrasena' => $accesoHabilitado,
            ])->save();
        } else {
            $nu = $nuBase;
            $i = 0;
            while (Usuario::query()->where('nombre_usuario', $nu)->exists()) {
                $nu = $nuBase.++$i;
            }
            $u = Usuario::create([
                'nombre_usuario' => $nu,
                'correo' => $correo,
                'contrasena_hash' => Hash::make($data['password']),
                'tipo' => 'empresa_cliente',
                'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
                'verificado' => $accesoHabilitado,
                'debe_cambiar_contrasena' => $accesoHabilitado,
            ]);
            $emp->update(['usuario_id' => $u->id]);
        }

        return $this->ok([
            'nombre_usuario' => $u->nombre_usuario,
            'correo' => $correo,
            'acceso_habilitado' => $accesoHabilitado,
            'nota' => $accesoHabilitado
                ? 'La empresa cliente debe cambiar la contraseña en el primer acceso.'
                : 'Usuario creado con acceso deshabilitado.',
        ], 'Credenciales definidas');
    }

    public function updateAccesoPortal(Request $request, int $id): JsonResponse
    {
        $cid = $this->consultoraId($request);
        if (! $cid) {
            return $this->fail('Sin consultora.', 403);
        }

        $data = $request->validate([
            'acceso_habilitado' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $emp = EmpresaCliente::query()->where('consultora_id', $cid)->with('usuario')->find($id);
        if (! $emp || ! $emp->usuario_id || ! $emp->usuario) {
            return $this->fail('No hay usuario de portal. Genera acceso primero.', 422);
        }

        $u = $emp->usuario;

        DB::transaction(function () use ($u, $data) {
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
            } else {
                $u->forceFill([
                    'estado' => 'inactivo',
                    'debe_cambiar_contrasena' => false,
                ])->save();
            }
        });

        return $this->ok([
            'acceso_portal_habilitado' => $u->fresh()->estado !== 'inactivo',
        ], 'Acceso al portal actualizado');
    }

    public function asignaciones(Request $request, int $id): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora.', 403);
        }

        $emp = EmpresaCliente::query()->where('consultora_id', $e->id)->find($id);
        if (! $emp) {
            return $this->fail('No encontrada', 404);
        }

        $data = $request->validate([
            'colaborador_ids' => ['required', 'array'],
            'colaborador_ids.*' => ['integer'],
        ]);

        $sync = [];
        foreach ($data['colaborador_ids'] as $colId) {
            $sync[$colId] = [
                'activo' => true,
                'asignado_por' => $e->id,
            ];
        }
        $emp->colaboradores()->sync($sync);

        return $this->ok($emp->colaboradores()->get());
    }

    private function splitNombre(?string $full): array
    {
        if (! $full) {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return ['', ''];
        }
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $ap = array_pop($parts);

        return [implode(' ', $parts), $ap];
    }
}
