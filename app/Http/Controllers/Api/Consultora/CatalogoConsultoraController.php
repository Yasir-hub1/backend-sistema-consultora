<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\InstitucionFinanciera;
use Illuminate\Http\JsonResponse;

class CatalogoConsultoraController extends ApiController
{
    public function institucionesFinancieras(): JsonResponse
    {
        $rows = InstitucionFinanciera::query()
            ->where('activo', true)
            ->orderBy('tipo')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'tipo', 'orden']);

        return $this->ok(['data' => $rows]);
    }
}
