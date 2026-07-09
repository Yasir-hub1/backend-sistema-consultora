<?php

namespace App\Console\Commands;

use App\Models\Alerta;
use App\Models\TramiteRecordatorioEnviado;
use App\Support\TramiteFechas;
use Illuminate\Console\Command;

class TramitesRecordatoriosEstadoCommand extends Command
{
    protected $signature = 'tramites:recordatorios:estado';

    protected $description = 'Resumen del sistema de recordatorios (BD, alertas, scheduler)';

    public function handle(): int
    {
        $this->info('Estado del sistema de recordatorios de trámites');
        $this->line('Zona horaria: '.TramiteFechas::timezone());
        $this->line('Ahora ('.TramiteFechas::timezone().'): '.TramiteFechas::ahora()->toDateTimeString());
        $this->newLine();

        $this->line('Registros en tramite_recordatorios_enviados: '.TramiteRecordatorioEnviado::count());
        $this->line('Alertas módulo tramite_recordatorio: '.Alerta::where('modulo', 'tramite_recordatorio')->count());
        $this->line('Alertas sin leer: '.Alerta::where('modulo', 'tramite_recordatorio')->where('leida', false)->count());
        $this->newLine();

        $this->comment('Scheduler (bootstrap/app.php):');
        $this->line('  tramites:recordatorios → cada 5 minutos');
        $this->line('  tramites:renovar-periodos → diario 00:10');
        $this->newLine();

        $this->comment('Comandos útiles:');
        $this->line('  php artisan tramites:recordatorios --pendientes');
        $this->line('  php artisan tramites:recordatorios --historial=ID');
        $this->line('  php artisan tramites:recordatorios --tramite=ID --reenviar');
        $this->line('  php artisan schedule:work');

        return self::SUCCESS;
    }
}
