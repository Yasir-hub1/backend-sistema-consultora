<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $u = $request->user();
        $empresas = 0;
        $alertas = 0;

        if ($u->tipo === 'colaborador' && ($c = $u->colaborador)) {
            $empresas = $c->empresasCliente()->wherePivot('activo', true)->count();
            $alertas = Alerta::query()
                ->where('colaborador_asignado', $c->id)
                ->where('resuelta', false)
                ->count();
        }

        if ($u->tipo === 'consultora' && ($e = $u->empresaConsultoraTitular)) {
            $empresas = $e->empresasCliente()->count();
            $alertas = Alerta::query()->where('consultora_id', $e->id)->where('resuelta', false)->count();
        }

        return $this->ok([
            'empresas_asignadas' => $empresas,
            'alertas_pendientes' => $alertas,
        ]);
    }
}
