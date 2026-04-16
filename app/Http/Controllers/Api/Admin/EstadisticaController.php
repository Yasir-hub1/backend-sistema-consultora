<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaConsultora;
use Illuminate\Http\JsonResponse;

class EstadisticaController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        $total = EmpresaConsultora::query()->count();
        $pendientes = EmpresaConsultora::query()->where('estado', 'pendiente_activacion')->count();

        return $this->ok([
            'total_consultoras' => $total,
            'consultoras_pendientes' => $pendientes,
            'consultoras' => $total,
        ]);
    }
}
