<?php

namespace App\Services;

use App\Models\Tramite;
use App\Models\Usuario;
use App\Support\TramiteFechas;
use Carbon\Carbon;

class TramiteRecurrenciaService
{
    public const FRECUENCIA_MENSUAL = 'mensual';

    /** Tipos que suelen ser mensuales y recurrentes. */
    public const TIPOS_RECURRENTES_SUGERIDOS = [
        'afp_mensual',
        'caja_mensual',
        'ministerio_mensual',
        'planilla_sueldos',
    ];

    public function esTipoRecurrenteSugerido(string $tipo): bool
    {
        return in_array($tipo, self::TIPOS_RECURRENTES_SUGERIDOS, true);
    }

    public function periodoDesdeFecha(Carbon $fecha): string
    {
        return $fecha->copy()->timezone(TramiteFechas::timezone())->format('Y-m');
    }

    public function etiquetaPeriodo(?string $periodo): ?string
    {
        if (! $periodo || ! preg_match('/^\d{4}-\d{2}$/', $periodo)) {
            return null;
        }

        [$anio, $mes] = array_map('intval', explode('-', $periodo));

        return Carbon::create($anio, $mes, 1, 0, 0, 0, TramiteFechas::timezone())
            ->locale('es')
            ->translatedFormat('F Y');
    }

    public function fechaVencimientoEnMes(int $anio, int $mes, int $dia): Carbon
    {
        $dia = max(1, min(28, $dia));
        $finMes = Carbon::create($anio, $mes, 1, 0, 0, 0, TramiteFechas::timezone())->endOfMonth()->day;
        $dia = min($dia, $finMes);

        return Carbon::create($anio, $mes, $dia, 0, 0, 0, TramiteFechas::timezone())->startOfDay();
    }

    public function configurarRecurrenciaEnCreacion(
        Tramite $tramite,
        bool $esRecurrente,
        ?Carbon $fechaVencimiento,
        bool $notificarCadaPeriodo = true
    ): Tramite {
        if (! $esRecurrente) {
            $tramite->es_recurrente = false;
            $tramite->frecuencia = null;
            $tramite->dia_vencimiento_mes = null;
            $tramite->periodo_actual = null;
            $tramite->notificar_cada_periodo = false;
            $tramite->save();

            return $tramite;
        }

        $base = $fechaVencimiento
            ? $fechaVencimiento->copy()->timezone(TramiteFechas::timezone())
            : TramiteFechas::ahora();
        $dia = (int) $base->day;
        $periodo = $this->periodoDesdeFecha($base);

        $tramite->es_recurrente = true;
        $tramite->frecuencia = self::FRECUENCIA_MENSUAL;
        $tramite->dia_vencimiento_mes = $dia;
        $tramite->periodo_actual = $periodo;
        $tramite->notificar_cada_periodo = $notificarCadaPeriodo;
        $tramite->recurrencia_activa = true;
        $tramite->recurrencia_anulada_en = null;

        if (! $tramite->fecha_vencimiento) {
            $tramite->fecha_vencimiento = $this->fechaVencimientoEnMes(
                (int) $base->year,
                (int) $base->month,
                $dia
            );
        }

        $tramite->save();

        return $tramite;
    }

    public function vencimientoDateTime(Tramite $tramite): ?Carbon
    {
        return TramiteFechas::fechaVencimiento($tramite->fecha_vencimiento);
    }

    public function clavePeriodoRecordatorio(Tramite $tramite): ?string
    {
        if ($tramite->es_recurrente) {
            return $tramite->periodo_actual;
        }

        $fecha = TramiteFechas::parseSoloDia($tramite->fecha_vencimiento);

        return $fecha?->toDateString();
    }

    public function renovarPeriodosMensuales(TramiteService $tramiteService): int
    {
        $periodoActual = TramiteFechas::periodoActual();
        $renovados = 0;

        Tramite::query()
            ->where('es_recurrente', true)
            ->where('recurrencia_activa', true)
            ->where('frecuencia', self::FRECUENCIA_MENSUAL)
            ->where(function ($q) use ($periodoActual) {
                $q->whereNull('periodo_actual')
                    ->orWhere('periodo_actual', '<', $periodoActual);
            })
            ->orderBy('id')
            ->chunkById(50, function ($tramites) use ($tramiteService, $periodoActual, &$renovados) {
                foreach ($tramites as $tramite) {
                    $this->renovarPeriodoMensual($tramite, $tramiteService, $periodoActual);
                    $renovados++;
                }
            });

        return $renovados;
    }

    public function renovarPeriodoMensual(
        Tramite $tramite,
        TramiteService $tramiteService,
        ?string $periodoObjetivo = null
    ): Tramite {
        $periodoObjetivo ??= TramiteFechas::periodoActual();
        [$anio, $mes] = array_map('intval', explode('-', $periodoObjetivo));
        $dia = (int) ($tramite->dia_vencimiento_mes ?? $tramite->fecha_vencimiento?->day ?? 15);
        $fechaVenc = $this->fechaVencimientoEnMes($anio, $mes, $dia);

        $periodoAnterior = $tramite->periodo_actual;

        $tramite->periodo_actual = $periodoObjetivo;
        $tramite->fecha_vencimiento = $fechaVenc;
        $tramite->estado = 'pendiente';
        $tramite->progreso_pct = 0;
        $tramite->completado_en = null;
        $tramite->save();

        $tramite->load('tareas');
        foreach ($tramite->tareas as $tarea) {
            $tarea->update([
                'estado' => 'pendiente',
                'completada_en' => null,
            ]);
        }

        $etiqueta = $this->etiquetaPeriodo($periodoObjetivo);
        $tramiteService->registrarEvento(
            $tramite,
            'periodo_renovado',
            'Nuevo período mensual',
            $periodoAnterior
                ? "El trámite se renovó para {$etiqueta}. Las tareas quedaron pendientes para este período."
                : "Se inició el período {$etiqueta}.",
            null,
            ['periodo' => $periodoObjetivo, 'periodo_anterior' => $periodoAnterior]
        );

        if ($tramite->notificar_cada_periodo) {
            $tramiteService->notificarPeriodoRenovado($tramite->fresh(['empresaCliente', 'colaboradoresAsignados']));
        }

        return $tramite->fresh(['tareas']);
    }

    public function reiniciarSiPeriodoCambiado(Tramite $tramite, TramiteService $tramiteService): Tramite
    {
        if (! $tramite->es_recurrente || $tramite->frecuencia !== self::FRECUENCIA_MENSUAL || ! $tramite->recurrencia_activa) {
            return $tramite;
        }

        $periodoActual = TramiteFechas::periodoActual();
        if ($tramite->periodo_actual === $periodoActual) {
            return $tramite;
        }

        if ($tramite->periodo_actual === null || $tramite->periodo_actual < $periodoActual) {
            return $this->renovarPeriodoMensual($tramite, $tramiteService, $periodoActual);
        }

        return $tramite;
    }

    public function anularRecurrencia(Tramite $tramite, TramiteService $tramiteService, ?Usuario $usuario = null): Tramite
    {
        return $tramiteService->anularTramite($tramite, $usuario);
    }

    /**
     * Proyecta vencimientos mensuales de un trámite recurrente dentro del rango visible.
     *
     * @return array<int, array<string, mixed>>
     */
    public function eventosCalendarioEnRango(Tramite $tramite, Carbon $desde, Carbon $hasta): array
    {
        if (! $tramite->es_recurrente || ! $tramite->recurrencia_activa || ! $tramite->dia_vencimiento_mes) {
            return [];
        }

        $tz = TramiteFechas::timezone();
        $desde = $desde->copy()->timezone($tz);
        $hasta = $hasta->copy()->timezone($tz);

        $dia = (int) $tramite->dia_vencimiento_mes;
        $cursor = $desde->copy()->startOfMonth();
        $fin = $hasta->copy()->startOfMonth();
        $eventos = [];

        while ($cursor <= $fin) {
            $fecha = $this->fechaVencimientoEnMes((int) $cursor->year, (int) $cursor->month, $dia);
            if ($fecha->gte($desde->copy()->startOfDay()) && $fecha->lte($hasta->copy()->endOfDay())) {
                $periodo = $this->periodoDesdeFecha($fecha);
                $esPeriodoActual = $tramite->periodo_actual === $periodo;
                $eventos[] = [
                    'fecha' => $fecha,
                    'periodo' => $periodo,
                    'estado' => $this->estadoProyectadoCalendario($tramite, $periodo, $fecha),
                    'es_periodo_actual' => $esPeriodoActual,
                ];
            }
            $cursor->addMonth();
        }

        return $eventos;
    }

    private function estadoProyectadoCalendario(Tramite $tramite, string $periodo, Carbon $fecha): string
    {
        if ($tramite->periodo_actual === $periodo) {
            return $tramite->estado;
        }

        if ($tramite->periodo_actual && $periodo < $tramite->periodo_actual) {
            return 'completado';
        }

        $ahora = TramiteFechas::ahora();
        if ($fecha->copy()->endOfDay()->lt($ahora->copy()->startOfDay())) {
            return 'vencido';
        }

        return 'pendiente';
    }
}
