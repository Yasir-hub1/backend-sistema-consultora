<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ConfiguracionConsultora;
use App\Models\EmpresaCliente;
use App\Models\EmpresaConsultora;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carta "Detalle de aportes" que usa la consultora en Reportes.
 */
final class CartaAportesPdfService
{
    /**
     * @param  array{total_ganado: float, deposito_cns: float, aportes_gestora: float, aporte_solidario: float, planilla_mdt: float, seprec: float, total_aportes: float}  $montos
     */
    public function responder(
        EmpresaCliente $empresa,
        EmpresaConsultora $consultora,
        array $montos,
        int $anio,
        int $mes,
        bool $inline = false,
    ): Response {
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

        Carbon::setLocale('es');
        $mesNombre = $this->mesEspanol($mes);
        $ciudadCarta = trim((string) ($consultora->ciudad ?: 'Santa Cruz'));
        $fechaCarta = Carbon::now()->translatedFormat('d \d\e F \d\e Y');
        $empresaNombre = trim((string) ($empresa->nombre ?: $empresa->razon_social)) ?: 'Empresa';
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

        $pdf = Pdf::loadView('reports.resumen_aportes_mensual', [
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
            'fmt' => fn (float $n): string => number_format($n, 2, '.', ','),
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
        ])->setPaper('a4', 'portrait');

        $disposition = $inline ? 'inline' : 'attachment';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$nombreArchivo.'"',
        ]);
    }

    private function mesEspanol(int $mes): string
    {
        $nombres = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $nombres[$mes] ?? (string) $mes;
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
}
