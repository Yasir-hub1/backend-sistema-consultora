<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aportes mensuales CNS y Gestora sobre el total ganado.
 *
 * Tasas de la hoja de cálculo de la consultora:
 * CNS 10%; riesgo profesional 1,71%; riesgo común 1,71%; comisión 0,50%;
 * vivienda patronal 2%; patronal solidario 3,50%; solidario del asegurado 0,50%;
 * jubilación 10%. Subtotal plano Gestora = 19,92% del total ganado.
 *
 * El aporte nacional solidario es adicional y acumulativo: cada tasa se aplica
 * al excedente completo sobre su umbral cuando la diferencia es positiva.
 * Factores 0,0115, 0,0574 y 0,1148 (1,15%, 5,74% y 11,48%).
 * Ejemplo total ganado 38.000 → 287,50 + 746,20 + 344,40 = 1.378,10.
 */
final class GestoraAporteCalculator
{
    public const TASA_CNS = '0.10';

    public const TASA_JUBILACION = '0.10';

    public const TASA_RIESGO_PROFESIONAL = '0.0171';

    public const TASA_RIESGO_COMUN = '0.0171';

    public const TASA_COMISION = '0.0050';

    public const TASA_VIVIENDA = '0.0200';

    public const TASA_PATRONAL_SOLIDARIO = '0.0350';

    public const TASA_ASEGURADO_SOLIDARIO = '0.0050';

    public const UMBRAL_FONDO_1 = '13000.00';

    public const TASA_FONDO_1 = '0.0115';

    public const UMBRAL_FONDO_5 = '25000.00';

    public const TASA_FONDO_5 = '0.0574';

    public const UMBRAL_FONDO_10 = '35000.00';

    public const TASA_FONDO_10 = '0.1148';

    /** @var list<string> */
    private const CAMPOS_SUMA = [
        'total_ganado',
        'cns',
        'jubilacion',
        'riesgo_profesional',
        'riesgo_comun',
        'comision',
        'subtotal_sip',
        'vivienda',
        'patronal_solidario',
        'asegurado_solidario',
        'fondo_1',
        'fondo_5',
        'fondo_10',
        'subtotal_solidarios',
        'total_gestora',
        'total_general',
    ];

    public function normalizarMonto(mixed $valor): string
    {
        $texto = str_replace(',', '.', trim((string) $valor));
        if ($texto === '' || ! is_numeric($texto)) {
            return '0.00';
        }

        return $this->redondear($texto);
    }

    /**
     * @return array<string, string>
     */
    public function calcularTrabajador(mixed $totalGanado): array
    {
        $total = $this->normalizarMonto($totalGanado);

        $cns = $this->porcentaje($total, self::TASA_CNS);
        $jubilacion = $this->porcentaje($total, self::TASA_JUBILACION);
        $riesgoProfesional = $this->porcentaje($total, self::TASA_RIESGO_PROFESIONAL);
        $riesgoComun = $this->porcentaje($total, self::TASA_RIESGO_COMUN);
        $comision = $this->porcentaje($total, self::TASA_COMISION);
        $vivienda = $this->porcentaje($total, self::TASA_VIVIENDA);
        $patronalSolidario = $this->porcentaje($total, self::TASA_PATRONAL_SOLIDARIO);
        $aseguradoSolidario = $this->porcentaje($total, self::TASA_ASEGURADO_SOLIDARIO);
        $fondo1 = $this->tramo($total, self::UMBRAL_FONDO_1, self::TASA_FONDO_1);
        $fondo5 = $this->tramo($total, self::UMBRAL_FONDO_5, self::TASA_FONDO_5);
        $fondo10 = $this->tramo($total, self::UMBRAL_FONDO_10, self::TASA_FONDO_10);

        $subtotalSip = $this->sumar($jubilacion, $riesgoProfesional, $riesgoComun, $comision);
        $subtotalSolidarios = $this->sumar($patronalSolidario, $aseguradoSolidario, $fondo1, $fondo5, $fondo10);
        $totalGestora = $this->sumar($subtotalSip, $vivienda, $subtotalSolidarios);

        return [
            'total_ganado' => $total,
            'cns' => $cns,
            'jubilacion' => $jubilacion,
            'riesgo_profesional' => $riesgoProfesional,
            'riesgo_comun' => $riesgoComun,
            'comision' => $comision,
            'subtotal_sip' => $subtotalSip,
            'vivienda' => $vivienda,
            'patronal_solidario' => $patronalSolidario,
            'asegurado_solidario' => $aseguradoSolidario,
            'fondo_1' => $fondo1,
            'fondo_5' => $fondo5,
            'fondo_10' => $fondo10,
            'subtotal_solidarios' => $subtotalSolidarios,
            'total_gestora' => $totalGestora,
            'total_general' => $this->sumar($cns, $totalGestora),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, string>
     */
    public function sumarFilas(array $filas): array
    {
        $totales = [];
        foreach (self::CAMPOS_SUMA as $campo) {
            $totales[$campo] = '0.00';
        }

        foreach ($filas as $fila) {
            foreach (self::CAMPOS_SUMA as $campo) {
                $totales[$campo] = $this->sumar($totales[$campo], $this->normalizarMonto($fila[$campo] ?? '0'));
            }
        }

        return $totales;
    }

    /**
     * 19,42% = SIP + vivienda + patronal solidario (sin el 0,50% del asegurado ni el ANS).
     * 19,92% = 19,42% + solidario del asegurado. El ANS queda fuera de ambas referencias.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{referencia_1942: string, referencia_1992: string}
     */
    public function referenciasPlanas(array $filas): array
    {
        $sinAsegurado = '0.00';
        $conAsegurado = '0.00';

        foreach ($filas as $fila) {
            $base = $this->sumar(
                $this->normalizarMonto($fila['subtotal_sip'] ?? '0'),
                $this->normalizarMonto($fila['vivienda'] ?? '0'),
                $this->normalizarMonto($fila['patronal_solidario'] ?? '0'),
            );
            $sinAsegurado = $this->sumar($sinAsegurado, $base);
            $conAsegurado = $this->sumar(
                $conAsegurado,
                $base,
                $this->normalizarMonto($fila['asegurado_solidario'] ?? '0'),
            );
        }

        return [
            'referencia_1942' => $sinAsegurado,
            'referencia_1992' => $conAsegurado,
        ];
    }

    private function tramo(string $total, string $umbral, string $tasa): string
    {
        $exceso = bcsub($total, $umbral, 2);
        if (bccomp($exceso, '0.00', 2) !== 1) {
            return '0.00';
        }

        return $this->porcentaje($exceso, $tasa);
    }

    private function porcentaje(string $base, string $tasa): string
    {
        return $this->redondear(bcmul($base, $tasa, 8));
    }

    private function sumar(string ...$partes): string
    {
        $acumulado = '0.00';
        foreach ($partes as $parte) {
            $acumulado = bcadd($acumulado, $parte, 2);
        }

        return $acumulado;
    }

    /**
     * Medio hacia arriba, a 2 decimales. Solo montos no negativos.
     */
    private function redondear(string $valor): string
    {
        $negativo = str_starts_with($valor, '-');
        $absoluto = ltrim($valor, '-');
        if (! str_contains($absoluto, '.')) {
            $absoluto .= '.0';
        }

        $centavos = bcadd(bcmul($absoluto, '100', 8), '0.5', 8);
        $centavos = bcadd($centavos, '0', 0);
        $redondeado = bcdiv($centavos, '100', 2);

        return $negativo ? '-'.$redondeado : $redondeado;
    }
}
