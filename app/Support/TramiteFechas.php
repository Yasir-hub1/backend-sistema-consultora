<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Fechas/horas de trámites siempre en APP_TIMEZONE (America/La_Paz).
 */
final class TramiteFechas
{
    public static function timezone(): string
    {
        return (string) config('app.timezone', 'America/La_Paz');
    }

    public static function ahora(): CarbonInterface
    {
        return Carbon::now(self::timezone());
    }

    /** Parsea "YYYY-MM-DD" (o ISO) como medianoche local. */
    public static function parseSoloDia(null|string|\DateTimeInterface $fecha): ?Carbon
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        // Usar solo la parte calendario (Y-m-d) para evitar desfases por timezone del cast de Eloquent.
        if ($fecha instanceof CarbonInterface) {
            $part = $fecha->format('Y-m-d');
        } else {
            $part = substr((string) $fecha, 0, 10);
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $part)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $part, self::timezone())->startOfDay();
    }

    /** Fecha en la que debe dispararse un aviso N días antes del vencimiento. */
    public static function fechaAvisoAntes(string $fechaVencimiento, int $diasAntes): ?string
    {
        $vencimiento = self::parseSoloDia($fechaVencimiento);
        if (! $vencimiento) {
            return null;
        }

        return $vencimiento->copy()->subDays($diasAntes)->toDateString();
    }

    /** true si hoy (La Paz) está en o después del día de aviso y no pasó el vencimiento. */
    public static function esDiaDeAviso(string $fechaVencimiento, int $diasAntes): bool
    {
        $hoy = self::hoySoloDia();
        if ($hoy > $fechaVencimiento) {
            return false;
        }

        $fechaAviso = self::fechaAvisoAntes($fechaVencimiento, $diasAntes);
        if ($fechaAviso === null) {
            return false;
        }

        if ($hoy < $fechaAviso) {
            return false;
        }

        // Ventana de catch-up: el aviso de N días no se repite cuando ya corresponde uno más cercano.
        $limiteSuperior = $diasAntes > 1
            ? self::fechaAvisoAntes($fechaVencimiento, $diasAntes - 1)
            : $fechaVencimiento;

        return $limiteSuperior !== null && $hoy < $limiteSuperior;
    }

    /** true el día del vencimiento (solo fecha). */
    public static function esDiaVencimiento(string $fechaVencimiento): bool
    {
        return self::hoySoloDia() === substr($fechaVencimiento, 0, 10);
    }

    /** Fecha de vencimiento del trámite (solo día, inicio de día local). */
    public static function fechaVencimiento(
        Carbon|string|\DateTimeInterface|null $fecha
    ): ?Carbon {
        return self::parseSoloDia($fecha);
    }

    public static function esFechaPasada(Carbon|string|\DateTimeInterface $fecha): bool
    {
        $dia = self::parseSoloDia($fecha);
        if (! $dia) {
            return false;
        }

        return self::hoySoloDia() > $dia->toDateString();
    }

    public static function periodoActual(): string
    {
        return self::ahora()->format('Y-m');
    }

    public static function hoySoloDia(): string
    {
        return self::ahora()->toDateString();
    }
}
