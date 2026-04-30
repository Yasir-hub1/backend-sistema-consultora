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
        if ($request->has('leida')) {
            $q->where('leida', filter_var($request->get('leida'), FILTER_VALIDATE_BOOLEAN));
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

    public function marcarLeida(Request $request, int $id): JsonResponse
    {
        $alerta = $this->alertaAccesible($request, $id);
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
        $u = $request->user();

        if ($u->tipo === 'consultora' && ($e = $u->empresaConsultoraTitular)) {
            $n = Alerta::query()
                ->where('consultora_id', $e->id)
                ->where('leida', false)
                ->update(['leida' => true, 'leida_en' => now()]);
        } else {
            $c = $u->colaborador;
            if (! $c) {
                return $this->fail('Sin perfil colaborador.', 403);
            }
            $n = Alerta::query()
                ->where('consultora_id', $c->consultora_id)
                ->where('colaborador_asignado', $c->id)
                ->where('leida', false)
                ->update(['leida' => true, 'leida_en' => now()]);
        }

        return $this->ok(['actualizadas' => $n], $n > 0 ? 'Notificaciones marcadas como leídas.' : 'No había notificaciones pendientes.');
    }

    private function alertaAccesible(Request $request, int $id): ?Alerta
    {
        $u = $request->user();
        $alerta = Alerta::query()->find($id);
        if (! $alerta) {
            return null;
        }

        if ($u->tipo === 'consultora' && ($e = $u->empresaConsultoraTitular)) {
            return $alerta->consultora_id === $e->id ? $alerta : null;
        }

        $c = $u->colaborador;
        if (! $c) {
            return null;
        }

        if ($alerta->consultora_id !== $c->consultora_id || $alerta->colaborador_asignado !== $c->id) {
            return null;
        }

        return $alerta;
    }
}
