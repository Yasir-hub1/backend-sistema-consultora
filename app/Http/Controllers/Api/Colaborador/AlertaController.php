<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();

        if ($u->tipo === 'consultora' && ($e = $u->empresaConsultoraTitular)) {
            $q = Alerta::query()->where('consultora_id', $e->id);
        } else {
            $c = $u->colaborador;
            if (! $c) {
                return $this->fail('Sin perfil colaborador.', 403);
            }
            $q = Alerta::query()
                ->where('consultora_id', $c->consultora_id)
                ->where('colaborador_asignado', $c->id);
        }

        if ($request->has('resuelta')) {
            $q->where('resuelta', filter_var($request->get('resuelta'), FILTER_VALIDATE_BOOLEAN));
        }

        $p = $q->orderByRaw("CASE nivel WHEN 'urgente' THEN 0 ELSE 1 END")
            ->orderByDesc('creado_en')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return $this->ok([
            'data' => $p->items(),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
        ]);
    }
}
