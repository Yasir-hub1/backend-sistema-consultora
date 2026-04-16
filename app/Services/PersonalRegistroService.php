<?php

namespace App\Services;

use App\Models\Personal;
use App\Models\PersonalAfp;
use App\Models\PersonalCaja;
use App\Models\PersonalMinisterio;

class PersonalRegistroService
{
    /**
     * Crea o actualiza fichas AFP / CAJA / Ministerio (compatible con trigger PostgreSQL que ya inserta filas vacías).
     */
    public function crearConModulos(Personal $personal, ?array $afp = null, ?array $caja = null): void
    {
        $afp = $afp ?? [];
        $caja = $caja ?? [];

        PersonalAfp::query()->updateOrCreate(
            ['personal_id' => $personal->id],
            [
                'afp_nombre' => $afp['afp_nombre'] ?? $afp['nombre'] ?? null,
                'numero_afiliado' => $afp['numero_afiliado'] ?? $afp['nro_afp'] ?? null,
                'estado' => 'sin_datos',
            ]
        );

        PersonalCaja::query()->updateOrCreate(
            ['personal_id' => $personal->id],
            [
                'caja_nombre' => $caja['caja_nombre'] ?? $caja['nombre'] ?? null,
                'numero_asegurado' => $caja['numero_asegurado'] ?? $caja['nro_caja'] ?? null,
                'estado' => 'sin_datos',
            ]
        );

        PersonalMinisterio::query()->updateOrCreate(
            ['personal_id' => $personal->id],
            [
                'estado' => 'sin_datos',
            ]
        );
    }
}
