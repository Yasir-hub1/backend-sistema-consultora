<?php

declare(strict_types=1);

use App\Services\GestoraAporteCalculator;

beforeEach(function (): void {
    $this->calc = new GestoraAporteCalculator;
});

function planilla(GestoraAporteCalculator $calc, string ...$totales): array
{
    $filas = array_map(fn (string $total): array => $calc->calcularTrabajador($total), $totales);

    return $calc->consolidar($calc->sumarFilas($filas));
}

it('calcula SIP, vivienda y fondo solidario por trabajador como el formulario SIP', function (): void {
    $fila = $this->calc->calcularTrabajador('38000');

    expect($fila['cns'])->toBe('3800.00')
        ->and($fila['jubilacion'])->toBe('3800.00')
        ->and($fila['riesgo_profesional'])->toBe('649.80')
        ->and($fila['riesgo_comun'])->toBe('649.80')
        ->and($fila['comision'])->toBe('190.00')
        ->and($fila['subtotal_sip'])->toBe('5289.60')
        ->and($fila['vivienda'])->toBe('760.00')
        ->and($fila['patronal_solidario'])->toBe('1330.00')
        ->and($fila['asegurado_solidario'])->toBe('190.00')
        ->and($fila['fondo_solidario'])->toBe('1520.00')
        ->and($fila['aporte_afp'])->toBe('7569.60')
        ->and($fila['base_ans_1'])->toBe('25000.00')
        ->and($fila['base_ans_2'])->toBe('13000.00')
        ->and($fila['base_ans_3'])->toBe('3000.00');
});

it('aplica 1,15%, 5,74% y 11,48% sobre las bases excedentes', function (): void {
    $consolidado = planilla($this->calc, '38000');

    expect($consolidado['ans_1'])->toBe('287.50')
        ->and($consolidado['ans_2'])->toBe('746.20')
        ->and($consolidado['ans_3'])->toBe('344.40')
        ->and($consolidado['ans'])->toBe('1378.10')
        ->and($consolidado['gestora'])->toBe('8947.70')
        ->and($consolidado['total_general'])->toBe('12747.70');
});

it('redondea el aporte nacional solidario una sola vez sobre la suma de bases', function (): void {
    // Bases 1.30 × 3 = 3.90: por trabajador 0.01 × 3 = 0.03; sobre la suma 3.90 × 1,15% = 0.04.
    $consolidado = planilla($this->calc, '13001.30', '13001.30', '13001.30');

    expect($consolidado['ans_1'])->toBe('0.04')
        ->and($consolidado['ans'])->toBe('0.04');
});

it('ignora excedentes menores a 1 Bs. como el formulario Fondo Solidario', function (): void {
    $casi = $this->calc->calcularTrabajador('13000.50');
    $justo = $this->calc->calcularTrabajador('13001.00');
    $en25 = $this->calc->calcularTrabajador('25000');

    expect($casi['base_ans_1'])->toBe('0.00')
        ->and($justo['base_ans_1'])->toBe('1.00')
        ->and($en25['base_ans_1'])->toBe('12000.00')
        ->and($en25['base_ans_2'])->toBe('0.00');
});

it('redondea cada concepto a 2 decimales medio hacia arriba', function (): void {
    $fila = $this->calc->calcularTrabajador('1.47');

    expect($fila['riesgo_profesional'])->toBe('0.03')
        ->and($fila['riesgo_comun'])->toBe('0.03');
});

it('consolida AFP a pagar en 19,92% y deja el aporte nacional solidario aparte', function (): void {
    $consolidado = planilla($this->calc, '8500', '6200');

    expect($consolidado['total_ganado'])->toBe('14700.00')
        ->and($consolidado['cns'])->toBe('1470.00')
        ->and($consolidado['sip'])->toBe('2046.24')
        ->and($consolidado['vivienda'])->toBe('294.00')
        ->and($consolidado['fondo_solidario'])->toBe('588.00')
        ->and($consolidado['aporte_afp'])->toBe('2928.24')
        ->and($consolidado['referencia_1942'])->toBe('2854.74')
        ->and($consolidado['ans'])->toBe('0.00')
        ->and($consolidado['gestora'])->toBe('2928.24')
        ->and($consolidado['total_general'])->toBe('4398.24');
});

it('descarta montos con notación científica o texto', function (): void {
    expect($this->calc->normalizarMonto('1e3'))->toBe('0.00')
        ->and($this->calc->normalizarMonto('8500,5'))->toBe('8500.50')
        ->and($this->calc->normalizarMonto('abc'))->toBe('0.00');
});
