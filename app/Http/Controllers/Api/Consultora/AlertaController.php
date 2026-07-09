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

        $q = Alerta::query()
            ->where('consultora_id', $e->id)
            ->whereNull('colaborador_asignado')
            ->where('modulo', '!=', 'asignacion_empresa');

        if ($request->has('resuelta')) {
            $q->where('resuelta', filter_var($request->get('resuelta'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->has('leida')) {
            $q->where('leida', filter_var($request->get('leida'), FILTER_VALIDATE_BOOLEAN));
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

    public function marcarLeida(Request $request, int $id): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora.', 403);
        }

        $alerta = Alerta::query()
            ->whereKey($id)
            ->where('consultora_id', $e->id)
            ->whereNull('colaborador_asignado')
            ->where('modulo', '!=', 'asignacion_empresa')
            ->first();
        if (! $alerta) {
            return $this->fail('Alerta no encontrada.', 404);
        }

        if (! $alerta->leida) {
            $alerta->update(['leida' => true, 'leida_en' => now()]);
        }

        return $this->ok($alerta->fresh(), 'Marcada como leída.');
    }

    public function marcarTodasLeidas(Request $request): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora.', 403);
        }

        $n = Alerta::query()
            ->where('consultora_id', $e->id)
            ->whereNull('colaborador_asignado')
            ->where('modulo', '!=', 'asignacion_empresa')
            ->where('leida', false)
            ->update(['leida' => true, 'leida_en' => now()]);

        return $this->ok(['actualizadas' => $n], $n > 0 ? 'Notificaciones marcadas como leídas.' : 'No había notificaciones pendientes.');
    }
}
