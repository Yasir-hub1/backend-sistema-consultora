<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\ConfiguracionConsultora;
use App\Models\DeclaracionMensual;
use App\Models\EmpresaCliente;
use App\Models\EmpresaConsultora;
use App\Services\CartaAportesPdfService;
use App\Services\ColaboradorAutorizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class ReporteResumenAportesController extends ApiController
{
    public function __construct(
        private readonly CartaAportesPdfService $cartaAportesPdf,
    ) {}

    public function datos(Request $request): JsonResponse
    {
        $built = $this->resolverReporte($request);
        if ($built instanceof JsonResponse) {
            return $built;
        }

        [$empresa, $consultora, $cfg, $montos, $mesGestion, $anio, $mes] = $built;

        $fmt = fn (float $n) => number_format($n, 2, '.', ',');

        return $this->ok([
            'empresa_cliente' => $this->serializarEmpresa($empresa),
            'consultora' => $this->serializarConsultora($consultora, $cfg),
            'mes_gestion' => $mesGestion,
            'mes_nombre' => $this->mesEspanol($mes),
            'anio' => $anio,
            'montos' => $montos,
            'montos_fmt' => [
                'total_ganado' => $fmt($montos['total_ganado']),
                'deposito_cns' => $fmt($montos['deposito_cns']),
                'aportes_gestora' => $fmt($montos['aportes_gestora']),
                'aporte_solidario' => $fmt($montos['aporte_solidario']),
                'planilla_mdt' => $fmt($montos['planilla_mdt']),
                'seprec' => $fmt($montos['seprec']),
                'total_aportes' => $fmt($montos['total_aportes']),
            ],
        ]);
    }

    public function pdf(Request $request): Response|JsonResponse
    {
        $built = $this->resolverReporte($request);
        if ($built instanceof JsonResponse) {
            return $built;
        }

        [$empresa, $consultora, , $montos, , $anio, $mes] = $built;

        return $this->cartaAportesPdf->responder($empresa, $consultora, $montos, $anio, $mes);
    }

    /**
     * @return array{0: EmpresaCliente, 1: EmpresaConsultora, 2: ConfiguracionConsultora, 3: array<string, float>, 4: string, 5: int, 6: int}|JsonResponse
     */
    private function resolverReporte(Request $request): array|JsonResponse
    {
        $usuario = $request->user();
        $consultora = $usuario->empresaConsultoraTitular ?? $usuario->colaborador?->consultora;
        if (! $consultora) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $request->validate([
            'empresa_cliente_id' => ['required', 'integer'],
            'mes_gestion' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $empresaId = (int) $request->input('empresa_cliente_id');
        $mesGestion = (string) $request->input('mes_gestion');
        [$anio, $mes] = array_map('intval', explode('-', $mesGestion));

        $empresa = EmpresaCliente::query()
            ->where('consultora_id', $consultora->id)
            ->whereKey($empresaId)
            ->first();

        if (! $empresa) {
            return $this->fail('Empresa cliente no encontrada en su cartera.', 404);
        }

        if (! ColaboradorAutorizacionService::empresaAccesible($usuario, $empresa->id)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $rows = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $empresa->id)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->get();

        if ($rows->isEmpty()) {
            return $this->fail('No hay declaraciones mensuales registradas para esa empresa y periodo.', 422);
        }

        $cfg = ConfiguracionConsultora::query()
            ->where('consultora_id', $consultora->id)
            ->with('institucionFinanciera')
            ->first();

        if (! $cfg) {
            $cfg = new ConfiguracionConsultora([
                'titular_cuenta' => '',
                'banco' => '',
                'nro_cuenta' => '',
                'tipo_cuenta' => 'ahorro',
                'moneda' => 'BOB',
            ]);
        }

        $montos = $this->agregarMontos($rows);

        return [$empresa, $consultora, $cfg, $montos, $mesGestion, $anio, $mes];
    }

    /**
     * @param  Collection<int, DeclaracionMensual>  $rows
     * @return array{total_ganado: float, deposito_cns: float, aportes_gestora: float, aporte_solidario: float, planilla_mdt: float, seprec: float, total_aportes: float}
     */
    private function agregarMontos($rows): array
    {
        $tgVals = $rows->map(fn (DeclaracionMensual $r) => (float) $r->monto_total_ganado)->filter(fn ($v) => $v > 0);
        $totalGanado = $tgVals->isEmpty()
            ? (float) $rows->max(fn (DeclaracionMensual $r) => (float) $r->monto_total_ganado)
            : (float) $tgVals->max();

        $depositoCns = (float) $rows->sum(fn (DeclaracionMensual $r) => (float) $r->monto_deposito_cns);
        $aportesGestora = (float) $rows->sum(fn (DeclaracionMensual $r) => (float) $r->monto_aportes_gestoras);
        $aporteSolidario = (float) $rows->sum(fn (DeclaracionMensual $r) => (float) $r->monto_aporte_solidario_gestora);
        $planillaMdt = (float) $rows->sum(fn (DeclaracionMensual $r) => (float) $r->monto_planilla_mensual_mdt);
        $seprec = (float) $rows->sum(fn (DeclaracionMensual $r) => (float) $r->monto_seprec_registro_poder_consultora);

        $totalAportes = $depositoCns + $aportesGestora + $aporteSolidario + $planillaMdt + $seprec;

        return [
            'total_ganado' => $totalGanado,
            'deposito_cns' => $depositoCns,
            'aportes_gestora' => $aportesGestora,
            'aporte_solidario' => $aporteSolidario,
            'planilla_mdt' => $planillaMdt,
            'seprec' => $seprec,
            'total_aportes' => $totalAportes,
        ];
    }

    private function mesEspanol(int $mes): string
    {
        $n = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $n[$mes] ?? (string) $mes;
    }

    private function serializarEmpresa(EmpresaCliente $e): array
    {
        return [
            'id' => $e->id,
            'nombre' => $e->nombre,
            'razon_social' => $e->razon_social,
            'nit' => $e->nit,
            'rep_legal_nombres' => $e->rep_legal_nombres,
            'rep_legal_apellidos' => $e->rep_legal_apellidos,
        ];
    }

    private function serializarConsultora(EmpresaConsultora $c, ConfiguracionConsultora $cfg): array
    {
        return [
            'razon_social' => $c->razon_social,
            'nombre_comercial' => $c->nombre_comercial,
            'nit' => $c->nit,
            'ciudad' => $c->ciudad,
            'cuenta' => [
                'titular' => $cfg->titular_cuenta,
                'banco' => $cfg->banco ?: $cfg->institucionFinanciera?->nombre,
                'nro_cuenta' => $cfg->nro_cuenta,
                'tipo_cuenta' => $cfg->tipo_cuenta,
                'moneda' => $cfg->moneda,
            ],
        ];
    }
}
