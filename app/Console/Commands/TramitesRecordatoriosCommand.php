<?php

namespace App\Console\Commands;

use App\Services\TramiteRecordatorioService;
use Illuminate\Console\Command;

class TramitesRecordatoriosCommand extends Command
{
    protected $signature = 'tramites:recordatorios
        {--dry-run : Simula sin crear alertas ni guardar en BD}
        {--pendientes : Lista recordatorios que deberían enviarse ahora}
        {--historial= : Muestra historial de envíos del trámite (ID)}
        {--tramite= : Filtra por ID de trámite}
        {--tipo= : Filtra por tipo (vencimiento_3d, vencimiento_1d, vencimiento_hoy)}
        {--reenviar : Borra el registro previo y vuelve a enviar (requiere --tramite)}';

    protected $description = 'Envía recordatorios de vencimiento de trámites (3d, 1d, día del vencimiento)';

    public function handle(TramiteRecordatorioService $recordatorios): int
    {
        $tramiteId = $this->option('tramite') ? (int) $this->option('tramite') : null;
        $tipo = $this->option('tipo') ?: null;

        if ($historialId = $this->option('historial')) {
            return $this->mostrarHistorial($recordatorios, (int) $historialId);
        }

        if ($this->option('pendientes')) {
            return $this->mostrarPendientes($recordatorios, $tramiteId);
        }

        if ($this->option('reenviar') && ! $tramiteId) {
            $this->error('Para reenviar debe indicar --tramite=ID');

            return self::FAILURE;
        }

        if ($tipo && ! in_array($tipo, TramiteRecordatorioService::TIPOS, true)) {
            $this->error('Tipo inválido. Use: vencimiento_3d, vencimiento_1d, vencimiento_hoy');

            return self::FAILURE;
        }

        $resultado = $recordatorios->procesarRecordatorios([
            'dry_run' => (bool) $this->option('dry-run'),
            'tramite_id' => $tramiteId,
            'tipo' => $tipo,
            'reenviar' => (bool) $this->option('reenviar'),
        ]);

        $modo = $this->option('dry-run') ? 'simulados' : 'enviados';

        $this->info("Recordatorios {$modo}: {$resultado['enviados']}");
        $this->line("Pendientes detectados: {$resultado['pendientes']}");
        $this->line("Omitidos (ya en BD): {$resultado['omitidos']}");

        if ($resultado['detalle'] !== []) {
            $this->newLine();
            $this->table(
                ['Trámite', 'ID', 'Tipo', 'Período', 'Vencimiento', 'Acción'],
                collect($resultado['detalle'])->map(fn ($r) => [
                    $r['tramite'],
                    $r['tramite_id'],
                    $r['tipo'],
                    $r['periodo'],
                    $r['vencimiento'],
                    $r['accion'],
                ])->all()
            );
        }

        $this->newLine();
        $this->comment('Consultar historial: php artisan tramites:recordatorios --historial=ID');
        $this->comment('Reenviar uno:       php artisan tramites:recordatorios --tramite=ID --reenviar');

        return self::SUCCESS;
    }

    private function mostrarPendientes(TramiteRecordatorioService $recordatorios, ?int $tramiteId): int
    {
        $pendientes = $recordatorios->listarPendientes($tramiteId);

        if ($pendientes->isEmpty()) {
            $this->info('No hay recordatorios pendientes en este momento.');

            return self::SUCCESS;
        }

        $this->info("Recordatorios pendientes: {$pendientes->count()}");
        $this->table(
            ['Trámite', 'ID', 'Tipo', 'Período', 'Vencimiento'],
            $pendientes->map(fn ($r) => [
                $r['tramite'],
                $r['tramite_id'],
                $r['tipo'],
                $r['periodo'],
                $r['vencimiento'],
            ])->all()
        );

        return self::SUCCESS;
    }

    private function mostrarHistorial(TramiteRecordatorioService $recordatorios, int $tramiteId): int
    {
        $rows = $recordatorios->historialTramite($tramiteId);

        if ($rows->isEmpty()) {
            $this->warn("Sin recordatorios registrados para el trámite #{$tramiteId}.");

            return self::SUCCESS;
        }

        $this->info("Historial de recordatorios — trámite #{$tramiteId}");
        $this->table(
            ['ID', 'Tipo', 'Período', 'Enviado', 'Reintentos', 'Alertas (IDs)'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->tipo,
                $r->periodo,
                $r->enviado_en?->toDateTimeString(),
                $r->reintentos,
                is_array($r->alerta_ids) ? implode(', ', $r->alerta_ids) : '—',
            ])->all()
        );

        return self::SUCCESS;
    }
}
