<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora.', 403);
        }

        $q = Alerta::query()->where('consultora_id', $e->id);

        if ($request->has('resuelta')) {
            $q->where('resuelta', filter_var($request->get('resuelta'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($n = $request->get('nivel')) {
            $q->where('nivel', $n);
        }
        if ($m = $request->get('modulo')) {
            $q->where('modulo', $m);
        }

        $p = $q->orderByRaw("CASE nivel WHEN 'urgente' THEN 0 ELSE 1 END")
            ->orderBy('fecha_vencimiento')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return $this->ok([
            'data' => $p->items(),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
        ]);
    }
}
