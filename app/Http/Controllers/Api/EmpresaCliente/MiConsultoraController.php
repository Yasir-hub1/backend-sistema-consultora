<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MiConsultoraController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $emp = $request->user()->empresaClienteComoUsuario;
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $emp->load('consultora.configuracion');
        $c = $emp->consultora;
        $cfg = $c?->configuracion;

        return $this->ok([
            'razon_social' => $c?->razon_social,
            'nombre_comercial' => $c?->nombre_comercial,
            'banco' => $cfg?->banco,
            'nro_cuenta' => $cfg?->nro_cuenta,
            'titular_cuenta' => $cfg?->titular_cuenta,
            'moneda' => $cfg?->moneda,
            'correo_soporte' => $cfg?->correo_soporte,
            'telefono_contacto' => $cfg?->telefono_soporte,
            'telefono' => $cfg?->telefono_soporte,
        ]);
    }
}
