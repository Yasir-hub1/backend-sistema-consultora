<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaCliente;
use App\Services\ColaboradorAutorizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmpresaAsignadaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $perPage = min((int) $request->get('per_page', 15), 100);

        if ($u->tipo === 'consultora' && ($e = $u->empresaConsultoraTitular)) {
            $totalCartera = $e->empresasCliente()->count();

            $q = $e->empresasCliente()
                ->with('usuario')
                ->withCount(['otrosDocumentosPersonal as otros_documentos_count', 'documentosEmpresa as documentos_legales_count'])
                ->orderBy('nombre');
            if ($s = trim((string) $request->get('search'))) {
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

            $p = $q->paginate($perPage);

            return $this->ok([
                'data' => $this->mapEmpresaRows($p->items()),
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
                'stats' => [
                    'en_cartera' => $totalCartera,
                ],
            ]);
        }

        $c = $u->colaborador;
        if (! $c) {
            return $this->fail('Sin perfil colaborador.', 403);
        }

        $totalAsignadas = $c->empresasCliente()->wherePivot('activo', true)->count();

        $q = $c->empresasCliente()
            ->wherePivot('activo', true)
            ->with('usuario')
            ->withCount(['otrosDocumentosPersonal as otros_documentos_count', 'documentosEmpresa as documentos_legales_count'])
            ->orderBy('empresas_cliente.nombre');

        if ($s = trim((string) $request->get('search'))) {
            if ($s !== '') {
                $like = '%'.$s.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('empresas_cliente.nombre', 'like', $like)
                        ->orWhere('empresas_cliente.nit', 'like', $like)
                        ->orWhere('empresas_cliente.razon_social', 'like', $like)
                        ->orWhere('empresas_cliente.correo_empresa', 'like', $like)
                        ->orWhere('empresas_cliente.ciudad', 'like', $like);
                });
            }
        }

        $p = $q->paginate($perPage);

        return $this->ok([
            'data' => $this->mapEmpresaRows($p->items()),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
            'stats' => [
                'asignadas' => $totalAsignadas,
            ],
        ]);
    }

    /**
     * @param  array<int, EmpresaCliente>  $items
     * @return array<int, array<string, mixed>>
     */
    private function mapEmpresaRows(array $items): array
    {
        return collect($items)->map(function (EmpresaCliente $emp) {
            $row = $emp->toArray();
            $u = $emp->usuario;
            $row['acceso_portal_habilitado'] = $emp->usuario_id && $u && $u->estado !== 'inactivo';
            $row['usuario_estado'] = $u?->estado;

            return $row;
        })->all();
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $emp = ColaboradorAutorizacionService::empresaAccesible($request->user(), $id);
        if (! $emp) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeEditarEmpresaCliente($request->user(), $id)) {
            return $this->fail('No autorizado para editar datos de la empresa.', 403);
        }

        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:200'],
            'nit' => ['sometimes', 'string', 'max:30'],
            'razon_social' => ['nullable', 'string', 'max:200'],
            'ciudad' => ['nullable', 'string', 'max:100'],
            'departamento' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'string'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'correo_empresa' => ['nullable', 'email', 'max:150'],
            'actividad_economica' => ['nullable', 'string', 'max:200'],
            'matricula_comercio' => ['nullable', 'string', 'max:50'],
            'rep_legal_nombres' => ['nullable', 'string', 'max:100'],
            'rep_legal_apellidos' => ['nullable', 'string', 'max:100'],
            'rep_legal_ci' => ['nullable', 'string', 'max:20'],
            'observaciones' => ['nullable', 'string'],
        ]);

        if ($data === []) {
            return $this->fail('No hay datos para actualizar.', 422);
        }

        if (isset($data['nit'])) {
            $dup = EmpresaCliente::query()
                ->where('consultora_id', $emp->consultora_id)
                ->where('nit', $data['nit'])
                ->where('id', '!=', $emp->id)
                ->exists();
            if ($dup) {
                return $this->fail('NIT ya registrado para esta consultora.', 422);
            }
        }

        $emp->fill($data);
        $emp->save();

        $fresh = $emp->fresh()->load('usuario');
        $row = $fresh->toArray();
        $u = $fresh->usuario;
        $row['acceso_portal_habilitado'] = $fresh->usuario_id && $u && $u->estado !== 'inactivo';
        $row['usuario_estado'] = $u?->estado;

        return $this->ok($row);
    }
}
