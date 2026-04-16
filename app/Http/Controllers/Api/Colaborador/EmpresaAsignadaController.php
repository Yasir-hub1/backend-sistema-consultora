<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaCliente;
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

            $q = $e->empresasCliente()->with('usuario')->orderBy('nombre');
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
}
