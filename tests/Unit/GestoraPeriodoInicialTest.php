<?php

declare(strict_types=1);

use App\Services\GestoraAporteCalculator;
use App\Services\GestoraPlanillaService;

beforeEach(function (): void {
    $this->gestora = new GestoraPlanillaService(new GestoraAporteCalculator);
});

it('omite el periodo de gestora cuando días y total vienen vacíos', function (): void {
    expect($this->gestora->normalizarPeriodoInicial(null, '  '))->toBe(['omitir' => true]);
});

it('completa 30 días cuando solo llega el total ganado', function (): void {
    $periodo = $this->gestora->normalizarPeriodoInicial('', '8500,5');

    expect($periodo)->toBe([
        'omitir' => false,
        'dias' => 30,
        'total' => '8500.50',
    ]);
});

it('rechaza días y montos que no se pueden cargar en la planilla', function (): void {
    expect($this->gestora->normalizarPeriodoInicial(32, null)['error'] ?? null)
        ->toBe('Los días trabajados deben ser un entero entre 0 y 31.')
        ->and($this->gestora->normalizarPeriodoInicial(30, '8.500,00')['error'] ?? null)
        ->toBe('El total ganado debe ser un monto válido, por ejemplo 8500.00.');
});
