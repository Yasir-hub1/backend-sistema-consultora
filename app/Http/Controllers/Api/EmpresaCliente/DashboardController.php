<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $emp = $request->user()->empresaClienteComoUsuario;
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $emp->load('consultora');

        return $this->ok([
            'empresa' => $emp->only(['id', 'nombre', 'nit', 'razon_social']),
            'consultora' => $emp->consultora?->only(['id', 'razon_social', 'nombre_comercial']),
        ]);
    }
}
