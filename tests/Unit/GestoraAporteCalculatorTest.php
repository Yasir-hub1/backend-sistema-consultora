<?php

declare(strict_types=1);

use App\Services\GestoraAporteCalculator;

beforeEach(function (): void {
    $this->calc = new GestoraAporteCalculator;
});

it('calcula el ejemplo progresivo de 38000 como 250 + 650 + 300', function (): void {
    $fila = $this->calc->calcularTrabajador('38000');

    expect($fila['fondo_1'])->toBe('250.00')
        ->and($fila['fondo_5'])->toBe('650.00')
        ->and($fila['fondo_10'])->toBe('300.00')
        ->and($fila['cns'])->toBe('3800.00')
        ->and($fila['jubilacion'])->toBe('3800.00')
        ->and($fila['riesgo_profesional'])->toBe('649.80')
        ->and($fila['comision'])->toBe('190.00')
        ->and($fila['vivienda'])->toBe('760.00')
        ->and($fila['patronal_solidario'])->toBe('1330.00')
        ->and($fila['asegurado_solidario'])->toBe('190.00')
        ->and($fila['subtotal_solidarios'])->toBe('2720.00')
        ->and($fila['total_gestora'])->toBe('8769.60');
});

it('deja en cero el aporte nacional solidario cuando el total no supera 13000', function (): void {
    $bajo = $this->calc->calcularTrabajador('8500.00');
    $umbral = $this->calc->calcularTrabajador('13000');

    expect($bajo['fondo_1'])->toBe('0.00')
        ->and($bajo['subtotal_sip'])->toBe('1183.20')
        ->and($bajo['patronal_solidario'])->toBe('297.50')
        ->and($bajo['subtotal_solidarios'])->toBe('340.00')
        ->and($bajo['total_gestora'])->toBe('1693.20')
        ->and($bajo['total_general'])->toBe('2543.20')
        ->and($umbral['fondo_1'])->toBe('0.00')
        ->and($umbral['fondo_5'])->toBe('0.00');
});

it('aplica solo los tramos cuya diferencia es positiva', function (): void {
    $en25 = $this->calc->calcularTrabajador('25000');
    $en35 = $this->calc->calcularTrabajador('35000');

    expect($en25['fondo_1'])->toBe('120.00')
        ->and($en25['fondo_5'])->toBe('0.00')
        ->and($en35['fondo_1'])->toBe('220.00')
        ->and($en35['fondo_5'])->toBe('500.00')
        ->and($en35['fondo_10'])->toBe('0.00');
});

it('redondea cada concepto a 2 decimales medio hacia arriba', function (): void {
    $fila = $this->calc->calcularTrabajador('1.47');

    expect($fila['riesgo_profesional'])->toBe('0.03')
        ->and($fila['riesgo_comun'])->toBe('0.03');
});

it('suma la planilla y separa la referencia 19,42% de la 19,92%', function (): void {
    $a = $this->calc->calcularTrabajador('8500');
    $b = $this->calc->calcularTrabajador('6200');
    $totales = $this->calc->sumarFilas([$a, $b]);
    $referencias = $this->calc->referenciasPlanas([$a, $b]);

    expect($totales['total_ganado'])->toBe('14700.00')
        ->and($totales['cns'])->toBe('1470.00')
        ->and($totales['total_gestora'])->toBe('2928.24')
        ->and($referencias['referencia_1992'])->toBe('2928.24')
        ->and($referencias['referencia_1942'])->toBe('2854.74');
});
