<?php

namespace App\Console\Commands;

use App\Services\TramiteRecurrenciaService;
use App\Services\TramiteService;
use Illuminate\Console\Command;

class TramitesRenovarPeriodosCommand extends Command
{
    protected $signature = 'tramites:renovar-periodos';

    protected $description = 'Renueva el período mensual de trámites recurrentes y reinicia sus tareas';

    public function handle(TramiteRecurrenciaService $recurrencia, TramiteService $tramiteService): int
    {
        $count = $recurrencia->renovarPeriodosMensuales($tramiteService);
        $this->info("Períodos renovados: {$count}");

        return self::SUCCESS;
    }
}
