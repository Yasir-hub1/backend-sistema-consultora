<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alerta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertaController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $empresa = $request->user()->empresaClienteComoUsuario;
        if (! $empresa) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $q = Alerta::query()
            ->where('consultora_id', $empresa->consultora_id)
            ->where('empresa_id', $empresa->id)
            ->where('modulo', 'declaracion_mensual');

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
        $empresa = $request->user()->empresaClienteComoUsuario;
        if (! $empresa) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $alerta = Alerta::query()
            ->whereKey($id)
            ->where('consultora_id', $empresa->consultora_id)
            ->where('empresa_id', $empresa->id)
            ->where('modulo', 'declaracion_mensual')
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
        $empresa = $request->user()->empresaClienteComoUsuario;
        if (! $empresa) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $n = Alerta::query()
            ->where('consultora_id', $empresa->consultora_id)
            ->where('empresa_id', $empresa->id)
            ->where('modulo', 'declaracion_mensual')
            ->where('leida', false)
            ->update(['leida' => true, 'leida_en' => now()]);

        return $this->ok(['actualizadas' => $n], $n > 0 ? 'Notificaciones marcadas como leídas.' : 'No había notificaciones pendientes.');
    }
}
