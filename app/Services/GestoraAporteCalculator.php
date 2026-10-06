<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aportes mensuales CNS y Gestora sobre el total ganado, según el formulario SIP de la Gestora.
 *
 * Por trabajador (cada concepto redondeado a 2 decimales):
 * CNS 10%; jubilación 10%; riesgo profesional 1,71%; riesgo común 1,71%; comisión 0,50%
 * (SIP 13,92%); vivienda patronal 2%; Fondo Solidario = patronal 3,50% + asegurado 0,50%.
 * AFP a pagar = SIP + vivienda + Fondo Solidario = 19,92%.
 *
 * Aporte nacional solidario (formulario Fondo Solidario): por trabajador solo se obtiene la base
 * excedente sobre 13.000, 25.000 y 35.000 (cero si la diferencia es menor a 1 Bs.). El aporte se
 * calcula una vez sobre la suma de cada base: 1,15%, 5,74% y 11,48%, redondeado a 2 decimales.
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

    public const UMBRAL_ANS_1 = '13000.00';

    public const TASA_ANS_1 = '0.0115';

    public const UMBRAL_ANS_2 = '25000.00';

    public const TASA_ANS_2 = '0.0574';

    public const UMBRAL_ANS_3 = '35000.00';

    public const TASA_ANS_3 = '0.1148';

    private const EXCEDENTE_MINIMO = '1.00';

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
        'fondo_solidario',
        'aporte_afp',
        'base_ans_1',
        'base_ans_2',
        'base_ans_3',
    ];

    public function normalizarMonto(mixed $valor): string
    {
        $texto = str_replace(',', '.', trim((string) $valor));
        if (! preg_match('/^-?\d+(\.\d+)?$/', $texto)) {
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

        $jubilacion = $this->porcentaje($total, self::TASA_JUBILACION);
        $riesgoProfesional = $this->porcentaje($total, self::TASA_RIESGO_PROFESIONAL);
        $riesgoComun = $this->porcentaje($total, self::TASA_RIESGO_COMUN);
        $comision = $this->porcentaje($total, self::TASA_COMISION);
        $vivienda = $this->porcentaje($total, self::TASA_VIVIENDA);
        $patronalSolidario = $this->porcentaje($total, self::TASA_PATRONAL_SOLIDARIO);
        $aseguradoSolidario = $this->porcentaje($total, self::TASA_ASEGURADO_SOLIDARIO);

        $subtotalSip = $this->sumar($jubilacion, $riesgoProfesional, $riesgoComun, $comision);
        $fondoSolidario = $this->sumar($patronalSolidario, $aseguradoSolidario);

        return [
            'total_ganado' => $total,
            'cns' => $this->porcentaje($total, self::TASA_CNS),
            'jubilacion' => $jubilacion,
            'riesgo_profesional' => $riesgoProfesional,
            'riesgo_comun' => $riesgoComun,
            'comision' => $comision,
            'subtotal_sip' => $subtotalSip,
            'vivienda' => $vivienda,
            'patronal_solidario' => $patronalSolidario,
            'asegurado_solidario' => $aseguradoSolidario,
            'fondo_solidario' => $fondoSolidario,
            'aporte_afp' => $this->sumar($subtotalSip, $vivienda, $fondoSolidario),
            'base_ans_1' => $this->excedente($total, self::UMBRAL_ANS_1),
            'base_ans_2' => $this->excedente($total, self::UMBRAL_ANS_2),
            'base_ans_3' => $this->excedente($total, self::UMBRAL_ANS_3),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, string>
     */
    public function sumarFilas(array $filas): array
    {
        $totales = array_fill_keys(self::CAMPOS_SUMA, '0.00');

        foreach ($filas as $fila) {
            foreach (self::CAMPOS_SUMA as $campo) {
                $totales[$campo] = $this->sumar($totales[$campo], $this->normalizarMonto($fila[$campo] ?? '0'));
            }
        }

        return $totales;
    }

    /**
     * @param  array<string, string>  $totales  Resultado de sumarFilas().
     * @return array<string, string>
     */
    public function consolidar(array $totales): array
    {
        $ans1 = $this->porcentaje($totales['base_ans_1'], self::TASA_ANS_1);
        $ans2 = $this->porcentaje($totales['base_ans_2'], self::TASA_ANS_2);
        $ans3 = $this->porcentaje($totales['base_ans_3'], self::TASA_ANS_3);
        $ans = $this->sumar($ans1, $ans2, $ans3);
        $gestora = $this->sumar($totales['aporte_afp'], $ans);

        return [
            'total_ganado' => $totales['total_ganado'],
            'cns' => $totales['cns'],
            'sip' => $totales['subtotal_sip'],
            'vivienda' => $totales['vivienda'],
            'fondo_solidario' => $totales['fondo_solidario'],
            'aporte_afp' => $totales['aporte_afp'],
            'referencia_1942' => $this->sumar($totales['subtotal_sip'], $totales['vivienda'], $totales['patronal_solidario']),
            'ans_1' => $ans1,
            'ans_2' => $ans2,
            'ans_3' => $ans3,
            'ans' => $ans,
            'gestora' => $gestora,
            'total_general' => $this->sumar($totales['cns'], $gestora),
        ];
    }

    public function sumar(string ...$partes): string
    {
        $acumulado = '0.00';
        foreach ($partes as $parte) {
            $acumulado = bcadd($acumulado, $parte, 2);
        }

        return $acumulado;
    }

    private function excedente(string $total, string $umbral): string
    {
        $exceso = bcsub($total, $umbral, 2);

        return bccomp($exceso, self::EXCEDENTE_MINIMO, 2) === -1 ? '0.00' : $exceso;
    }

    private function porcentaje(string $base, string $tasa): string
    {
        return $this->redondear(bcmul($base, $tasa, 8));
    }

    /**
     * Medio hacia arriba (lejos de cero), a 2 decimales.
     */
    private function redondear(string $valor): string
    {
        $negativo = str_starts_with($valor, '-');
        $absoluto = ltrim($valor, '-');

        $centavos = bcadd(bcmul($absoluto, '100', 8), '0.5', 8);
        $redondeado = bcdiv(bcadd($centavos, '0', 0), '100', 2);

        return $negativo && bccomp($redondeado, '0', 2) === 1 ? '-'.$redondeado : $redondeado;
    }
}
