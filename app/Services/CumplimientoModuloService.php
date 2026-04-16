<?php

namespace App\Services;

use App\Models\Documento;
use App\Models\Personal;
use App\Models\PersonalAfp;
use App\Models\PersonalCaja;
use App\Models\PersonalMinisterio;
use App\Models\TipoDocumento;

class CumplimientoModuloService
{
    public function recalcularPersonal(Personal $personal, string $modulo): void
    {
        $obligatorios = TipoDocumento::query()
            ->where('modulo', $modulo)
            ->where('obligatorio', true)
            ->where('activo', true)
            ->pluck('id');

        if ($obligatorios->isEmpty()) {
            $this->setEstadoModulo($personal, $modulo, 'sin_datos');

            return;
        }

        foreach ($obligatorios as $tipoId) {
            $ok = Documento::query()
                ->where('personal_id', $personal->id)
                ->where('modulo', $modulo)
                ->where('tipo_documento_id', $tipoId)
                ->where('es_vigente', true)
                ->where('eliminado', false)
                ->exists();

            if (! $ok) {
                $this->setEstadoModulo($personal, $modulo, 'pendiente');

                return;
            }
        }

        $this->setEstadoModulo($personal, $modulo, 'al_dia');
    }

    private function setEstadoModulo(Personal $personal, string $modulo, string $estado): void
    {
        match ($modulo) {
            'afp' => PersonalAfp::query()->where('personal_id', $personal->id)->update(['estado' => $estado]),
            'caja' => PersonalCaja::query()->where('personal_id', $personal->id)->update(['estado' => $estado]),
            'ministerio' => PersonalMinisterio::query()->where('personal_id', $personal->id)->update(['estado' => $estado]),
            default => null,
        };
    }
}
