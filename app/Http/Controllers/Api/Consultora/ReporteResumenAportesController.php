<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\ConfiguracionConsultora;
use App\Models\DeclaracionMensual;
use App\Models\EmpresaCliente;
use App\Models\EmpresaConsultora;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReporteResumenAportesController extends ApiController
{
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

        [$empresa, $consultora, $cfg, $montos, $mesGestion, $anio, $mes] = $built;

        Carbon::setLocale('es');
        $mesNombre = $this->mesEspanol($mes);
        $ciudadCarta = trim((string) ($consultora->ciudad ?: 'Santa Cruz'));
        $fechaCarta = Carbon::now()->translatedFormat('d \d\e F \d\e Y');

        $empresaNombre = $this->nombreEmpresa($empresa);
        $repLinea = trim(implode(' ', array_filter([
            $empresa->rep_legal_nombres,
            $empresa->rep_legal_apellidos,
        ])));

        $nombreArchivo = sprintf(
            'resumen_aportes_%s_%04d-%02d.pdf',
            Str::slug(Str::limit($empresaNombre, 40, '')) ?: 'empresa',
            $anio,
            $mes
        );

        $viewData = [
            'logoDataUri' => $this->logoDataUri($cfg),
            'consultoraTitulo' => $consultora->razon_social ?: $consultora->nombre_comercial,
            'consultoraSubtitulo' => $consultora->nombre_comercial && $consultora->razon_social && $consultora->nombre_comercial !== $consultora->razon_social
                ? $consultora->nombre_comercial
                : null,
            'fechaCarta' => $ciudadCarta.', '.$fechaCarta,
            'destinatarioNombre' => $repLinea !== '' ? $repLinea : null,
            'destinatarioEmpresa' => $empresaNombre,
            'refLinea1' => 'REF.: DETALLE DE APORTES MES DE '.mb_strtoupper($mesNombre).' '.$anio,
            'refLinea2' => mb_strtoupper($empresaNombre),
            'cuerpoEmpresa' => $empresaNombre,
            'tablaTitulo' => 'DETALLE DE APORTES '.mb_strtoupper($empresaNombre),
            'fmt' => fn (float $n) => number_format($n, 2, '.', ','),
            'totalGanado' => $montos['total_ganado'],
            'depositoCns' => $montos['deposito_cns'],
            'aportesGestora' => $montos['aportes_gestora'],
            'aporteSolidario' => $montos['aporte_solidario'],
            'planillaMdt' => $montos['planilla_mdt'],
            'planillaEtiqueta' => 'Planilla Mensual '.$mesNombre.' '.$anio.' (MDT)',
            'seprec' => $montos['seprec'],
            'totalAportes' => $montos['total_aportes'],
            'cuentaTitular' => $cfg->titular_cuenta,
            'cuentaBanco' => $cfg->banco ?: $cfg->institucionFinanciera?->nombre,
            'cuentaDetalle' => $this->lineaCuenta($cfg),
            'firmaNombre' => trim(implode(' ', array_filter([
                $consultora->representante_nombres,
                $consultora->representante_apellidos,
            ]))) ?: ($consultora->razon_social ?: $consultora->nombre_comercial),
            'firmaCargo' => 'Representante legal',
            'contactoDireccion' => trim(implode(', ', array_filter([
                $consultora->direccion,
                $consultora->ciudad,
                $consultora->departamento,
            ]))),
            'contactoTelefonos' => trim(implode(' - ', array_filter([
                $cfg->telefono_soporte,
                $consultora->telefono,
            ]))),
            'contactoCorreo' => $cfg->correo_soporte ?: $consultora->correo_principal,
            'contactoCiudad' => trim(($consultora->ciudad ?: 'Santa Cruz').' - Bolivia'),
        ];

        return Pdf::loadView('reports.resumen_aportes_mensual', $viewData)
            ->setPaper('a4', 'portrait')
            ->download($nombreArchivo);
    }

    /**
     * @return array{0: EmpresaCliente, 1: EmpresaConsultora, 2: ConfiguracionConsultora, 3: array<string, float>, 4: string, 5: int, 6: int}|JsonResponse
     */
    private function resolverReporte(Request $request): array|JsonResponse
    {
        $consultora = $request->user()->empresaConsultoraTitular;
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

    private function nombreEmpresa(EmpresaCliente $e): string
    {
        return trim((string) ($e->nombre ?: $e->razon_social)) ?: 'Empresa';
    }

    private function mesEspanol(int $mes): string
    {
        $n = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $n[$mes] ?? (string) $mes;
    }

    private function lineaCuenta(ConfiguracionConsultora $cfg): string
    {
        $tipo = match ($cfg->tipo_cuenta) {
            'corriente' => 'CUENTA CORRIENTE',
            default => 'CAJA DE AHORRO',
        };
        $moneda = $cfg->moneda ?: 'BOB';
        $nro = $cfg->nro_cuenta ?: '—';

        return $tipo.' '.$moneda.'. '.$nro;
    }

    private function logoDataUri(?ConfiguracionConsultora $cfg): ?string
    {
        $url = $cfg?->logo_url;
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $rel = preg_replace('#^/storage/#', '', $url);
        if (! is_string($rel) || $rel === '') {
            return null;
        }

        $path = Storage::disk('public')->path($rel);
        if (! is_readable($path)) {
            return null;
        }

        $mime = @mime_content_type($path);
        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
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
