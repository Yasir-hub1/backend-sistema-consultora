<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CUA (Código Único del Asegurado) / RUA: un solo identificador numérico ante la Gestora.
 */
final class NumeroCua
{
    /**
     * @return array{valor: ?string, error: ?string}
     */
    public static function resolver(mixed $raw): array
    {
        if ($raw === null) {
            return ['valor' => null, 'error' => null];
        }

        $texto = trim((string) $raw);
        if ($texto === '') {
            return ['valor' => null, 'error' => null];
        }

        if (preg_match('/\D/', str_replace([' ', '-'], '', $texto)) === 1) {
            return ['valor' => null, 'error' => 'El CUA/RUA solo admite números.'];
        }

        $digitos = preg_replace('/\D+/', '', $texto) ?? '';
        $largo = strlen($digitos);
        if ($largo < 4 || $largo > 20) {
            return ['valor' => null, 'error' => 'El CUA/RUA debe tener entre 4 y 20 dígitos.'];
        }

        return ['valor' => $digitos, 'error' => null];
    }
}
