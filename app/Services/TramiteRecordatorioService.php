<?php

namespace App\Services;

use App\Models\Tramite;
use App\Models\TramiteRecordatorioEnviado;
use App\Support\TramiteFechas;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TramiteRecordatorioService
{
    public const TIPOS = [
        'vencimiento_3d',
        'vencimiento_1d',
        'vencimiento_hoy',
    ];

    public function __construct(
        private TramiteRecurrenciaService $recurrencia,
        private TramiteService $tramiteService
    ) {}

    /**
     * @param  array{
     *   dry_run?: bool,
     *   tramite_id?: int|null,
     *   tipo?: string|null,
     *   reenviar?: bool,
     *   solo_pendientes?: bool,
     * }  $opciones
     * @return array{enviados: int, pendientes: int, omitidos: int, detalle: array<int, array<string, mixed>>}
     */
    public function procesarRecordatorios(array $opciones = []): array
    {
        $dryRun = (bool) ($opciones['dry_run'] ?? false);
        $tramiteId = isset($opciones['tramite_id']) ? (int) $opciones['tramite_id'] : null;
        $tipoFiltro = $opciones['tipo'] ?? null;
        $reenviar = (bool) ($opciones['reenviar'] ?? false);
        $soloPendientes = (bool) ($opciones['solo_pendientes'] ?? false);

        if ($reenviar && $tramiteId) {
            $q = TramiteRecordatorioEnviado::query()->where('tramite_id', $tramiteId);
            if ($tipoFiltro) {
                $q->where('tipo', $tipoFiltro);
            }
            $q->delete();
        }

        $enviados = 0;
        $omitidos = 0;
        $pendientes = 0;
        $detalle = [];
        $hoy = TramiteFechas::hoySoloDia();
        $tz = TramiteFechas::timezone();

        $query = Tramite::query()
            ->whereNotNull('fecha_vencimiento')
            ->where(function ($q) {
                $q->where('anulado', false)->orWhereNull('anulado');
            })
            ->where('estado', '!=', 'completado')
            ->where(function ($q) {
                $q->where('es_recurrente', false)
                    ->orWhereNull('es_recurrente')
                    ->orWhere(function ($q2) {
                        $q2->where('es_recurrente', true)
                            ->where(function ($q3) {
                                $q3->where('recurrencia_activa', true)
                                    ->orWhereNull('recurrencia_activa');
                            });
                    });
            })
            ->with(['empresaCliente:id,nombre,razon_social', 'colaboradoresAsignados:id,nombres,apellidos,usuario_id']);

        if ($tramiteId) {
            $query->whereKey($tramiteId);
        }

        $query->orderBy('id')->chunkById(50, function ($tramites) use (
            $hoy,
            $tz,
            $dryRun,
            $tipoFiltro,
            $soloPendientes,
            $reenviar,
            &$enviados,
            &$omitidos,
            &$pendientes,
            &$detalle
        ) {
            foreach ($tramites as $tramite) {
                if ($tramite->anulado || ($tramite->es_recurrente && $tramite->recurrencia_activa === false)) {
                    continue;
                }

                if ($tramite->es_recurrente) {
                    $tramite = $this->recurrencia->reiniciarSiPeriodoCambiado($tramite, $this->tramiteService);
                }

                $vencimiento = $this->recurrencia->vencimientoDateTime($tramite);
                if (! $vencimiento) {
                    continue;
                }

                $fechaVencimiento = TramiteFechas::parseSoloDia($vencimiento)?->toDateString();
                if (! $fechaVencimiento || $hoy > $fechaVencimiento) {
                    continue;
                }

                $periodoKey = $this->recurrencia->clavePeriodoRecordatorio($tramite);
                $tipos = $tipoFiltro ? [$tipoFiltro] : self::TIPOS;

                foreach ($tipos as $tipo) {
                    if (! in_array($tipo, self::TIPOS, true)) {
                        continue;
                    }

                    $debe = $this->debeEnviar($hoy, $fechaVencimiento, $tipo);
                    $ya = $this->yaEnviado($tramite->id, $periodoKey, $tipo);

                    if (! $debe) {
                        continue;
                    }

                    if ($ya) {
                        $omitidos++;
                        continue;
                    }

                    if ($soloPendientes && ! $debe) {
                        continue;
                    }

                    $pendientes++;

                    if ($dryRun) {
                        $detalle[] = [
                            'tramite_id' => $tramite->id,
                            'tramite' => $tramite->nombre,
                            'tipo' => $tipo,
                            'periodo' => $periodoKey,
                            'vencimiento' => $fechaVencimiento,
                            'zona_horaria' => $tz,
                            'accion' => 'enviaria',
                        ];
                        continue;
                    }

                    if ($this->enviarSiCorresponde($tramite, $periodoKey, $tipo, $vencimiento, $reenviar)) {
                        $enviados++;
                        $detalle[] = [
                            'tramite_id' => $tramite->id,
                            'tramite' => $tramite->nombre,
                            'tipo' => $tipo,
                            'periodo' => $periodoKey,
                            'vencimiento' => $fechaVencimiento,
                            'zona_horaria' => $tz,
                            'accion' => 'enviado',
                        ];
                    }
                }
            }
        });

        return compact('enviados', 'pendientes', 'omitidos', 'detalle');
    }

    /**
     * Lista recordatorios pendientes (ventana alcanzada, aún no enviados).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listarPendientes(?int $tramiteId = null): Collection
    {
        $resultado = $this->procesarRecordatorios([
            'dry_run' => true,
            'tramite_id' => $tramiteId,
            'solo_pendientes' => true,
        ]);

        return collect($resultado['detalle']);
    }

    /**
     * @return Collection<int, TramiteRecordatorioEnviado>
     */
    public function historialTramite(int $tramiteId): Collection
    {
        return TramiteRecordatorioEnviado::query()
            ->where('tramite_id', $tramiteId)
            ->orderByDesc('enviado_en')
            ->get();
    }

    private function debeEnviar(string $hoy, string $fechaVencimiento, string $tipo): bool
    {
        if ($hoy > $fechaVencimiento) {
            return false;
        }

        return match ($tipo) {
            'vencimiento_3d' => TramiteFechas::esDiaDeAviso($fechaVencimiento, 3),
            'vencimiento_1d' => TramiteFechas::esDiaDeAviso($fechaVencimiento, 1),
            'vencimiento_hoy' => TramiteFechas::esDiaVencimiento($fechaVencimiento),
            default => false,
        };
    }

    private function enviarSiCorresponde(
        Tramite $tramite,
        ?string $periodoKey,
        string $tipo,
        Carbon $vencimiento,
        bool $esReenvio = false
    ): bool {
        if (! $esReenvio && $this->yaEnviado($tramite->id, $periodoKey, $tipo)) {
            return false;
        }

        [$titulo, $descripcion, $nivel] = $this->mensajeRecordatorio($tramite, $tipo, $vencimiento);
        $alertaIds = $this->tramiteService->notificarRecordatorioTramite(
            $tramite,
            $tipo,
            $titulo,
            $descripcion,
            $nivel
        );

        $existente = TramiteRecordatorioEnviado::query()
            ->where('tramite_id', $tramite->id)
            ->where('periodo', $periodoKey)
            ->where('tipo', $tipo)
            ->first();

        if ($existente) {
            $existente->update([
                'enviado_en' => TramiteFechas::ahora(),
                'alerta_ids' => $alertaIds,
                'reintentos' => (int) $existente->reintentos + 1,
            ]);
        } else {
            TramiteRecordatorioEnviado::query()->create([
                'tramite_id' => $tramite->id,
                'periodo' => $periodoKey,
                'tipo' => $tipo,
                'alerta_ids' => $alertaIds,
                'reintentos' => $esReenvio ? 1 : 0,
                'enviado_en' => TramiteFechas::ahora(),
            ]);
        }

        return true;
    }

    private function yaEnviado(int $tramiteId, ?string $periodoKey, string $tipo): bool
    {
        return TramiteRecordatorioEnviado::query()
            ->where('tramite_id', $tramiteId)
            ->where('periodo', $periodoKey)
            ->where('tipo', $tipo)
            ->exists();
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function mensajeRecordatorio(Tramite $tramite, string $tipo, Carbon $vencimiento): array
    {
        $empresa = $tramite->empresaCliente?->nombre ?: $tramite->empresaCliente?->razon_social ?: 'empresa';
        $fechaTxt = $vencimiento->locale('es')->translatedFormat('j \d\e F \d\e Y');
        $periodoTxt = $tramite->es_recurrente && $tramite->periodo_actual
            ? ' ('.$this->recurrencia->etiquetaPeriodo($tramite->periodo_actual).')'
            : '';

        return match ($tipo) {
            'vencimiento_3d' => [
                'Vence en 3 días',
                "El trámite «{$tramite->nombre}»{$periodoTxt} de {$empresa} vence el {$fechaTxt}.",
                'normal',
            ],
            'vencimiento_1d' => [
                'Vence mañana',
                "El trámite «{$tramite->nombre}»{$periodoTxt} de {$empresa} vence mañana ({$fechaTxt}).",
                'urgente',
            ],
            'vencimiento_hoy' => [
                'Vence hoy',
                "El trámite «{$tramite->nombre}»{$periodoTxt} de {$empresa} vence hoy ({$fechaTxt}).",
                'urgente',
            ],
            default => [
                'Recordatorio de trámite',
                "Recordatorio para «{$tramite->nombre}»{$periodoTxt}.",
                'normal',
            ],
        };
    }
}
