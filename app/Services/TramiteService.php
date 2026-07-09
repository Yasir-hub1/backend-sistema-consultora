<?php

namespace App\Services;

use App\Models\Alerta;
use App\Models\Colaborador;
use App\Models\Tarea;
use App\Models\Tramite;
use App\Models\TramiteEvento;
use App\Models\Usuario;
use App\Support\TramiteFechas;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TramiteService
{
    /** Plantillas de tareas por tipo de trámite. */
    public const PLANTILLAS_TAREAS = [
        'afp_mensual' => [
            ['nombre' => 'Revisar planilla de aportes AFP', 'requiere_documento' => false],
            ['nombre' => 'Cargar declaración mensual AFP', 'requiere_documento' => true],
            ['nombre' => 'Validar comprobantes de depósito', 'requiere_documento' => true],
            ['nombre' => 'Registrar entrega al cliente', 'requiere_documento' => false],
        ],
        'caja_mensual' => [
            ['nombre' => 'Verificar régimen CAJA del personal', 'requiere_documento' => false],
            ['nombre' => 'Cargar declaración mensual CAJA', 'requiere_documento' => true],
            ['nombre' => 'Adjuntar comprobante CNS', 'requiere_documento' => true],
            ['nombre' => 'Cerrar gestión del mes', 'requiere_documento' => false],
        ],
        'ministerio_mensual' => [
            ['nombre' => 'Revisar planilla MDT', 'requiere_documento' => false],
            ['nombre' => 'Cargar declaración Ministerio de Trabajo', 'requiere_documento' => true],
            ['nombre' => 'Validar montos y totales', 'requiere_documento' => false],
            ['nombre' => 'Archivar entrega mensual', 'requiere_documento' => true],
        ],
        'alta_personal' => [
            ['nombre' => 'Registrar datos del trabajador', 'requiere_documento' => false],
            ['nombre' => 'Completar legajo base (CV, CI, etc.)', 'requiere_documento' => true],
            ['nombre' => 'Iniciar documentación AFP / CAJA / MDT', 'requiere_documento' => false],
            ['nombre' => 'Confirmar alta operativa', 'requiere_documento' => false],
        ],
        'documentacion_legal' => [
            ['nombre' => 'Solicitar documentos legales al cliente', 'requiere_documento' => false],
            ['nombre' => 'Subir NIT, ROE y matrícula', 'requiere_documento' => true],
            ['nombre' => 'Subir licencia y certificados', 'requiere_documento' => true],
            ['nombre' => 'Validar catálogo «Mi empresa»', 'requiere_documento' => false],
        ],
        'aguinaldo_anual' => [
            ['nombre' => 'Preparar planilla de aguinaldo', 'requiere_documento' => false],
            ['nombre' => 'Cargar declaración anual de aguinaldo', 'requiere_documento' => true],
            ['nombre' => 'Validar y entregar al cliente', 'requiere_documento' => true],
        ],
        'baja_personal' => [
            ['nombre' => 'Registrar motivo y fecha de baja', 'requiere_documento' => false],
            ['nombre' => 'Preparar finiquito y liquidación', 'requiere_documento' => true],
            ['nombre' => 'Tramitar baja en AFP y CAJA', 'requiere_documento' => true],
            ['nombre' => 'Entregar documentación al trabajador', 'requiere_documento' => false],
        ],
        'liquidacion_finiquito' => [
            ['nombre' => 'Calcular indemnización y beneficios', 'requiere_documento' => false],
            ['nombre' => 'Generar finiquito firmado', 'requiere_documento' => true],
            ['nombre' => 'Registrar pago y comprobante', 'requiere_documento' => true],
        ],
        'planilla_sueldos' => [
            ['nombre' => 'Consolidar asistencia y novedades', 'requiere_documento' => false],
            ['nombre' => 'Elaborar planilla de sueldos', 'requiere_documento' => true],
            ['nombre' => 'Validar con el cliente', 'requiere_documento' => false],
            ['nombre' => 'Archivar planilla del período', 'requiere_documento' => true],
        ],
        'certificacion_trabajo' => [
            ['nombre' => 'Solicitar datos del certificado', 'requiere_documento' => false],
            ['nombre' => 'Emitir certificación de trabajo', 'requiere_documento' => true],
            ['nombre' => 'Entregar al solicitante', 'requiere_documento' => false],
        ],
        'revision_contratos' => [
            ['nombre' => 'Recopilar contratos vigentes', 'requiere_documento' => false],
            ['nombre' => 'Revisar cláusulas y vencimientos', 'requiere_documento' => false],
            ['nombre' => 'Registrar observaciones y ajustes', 'requiere_documento' => true],
        ],
        'personalizado' => [
            ['nombre' => 'Definir y ejecutar el trámite', 'requiere_documento' => false],
        ],
    ];

    public const TIPOS_LABEL = [
        'afp_mensual' => 'Gestión mensual AFP',
        'caja_mensual' => 'Gestión mensual CAJA',
        'ministerio_mensual' => 'Gestión mensual Ministerio',
        'alta_personal' => 'Alta de personal',
        'documentacion_legal' => 'Documentación legal empresa',
        'aguinaldo_anual' => 'Declaración de aguinaldo',
        'baja_personal' => 'Baja de personal',
        'liquidacion_finiquito' => 'Liquidación y finiquito',
        'planilla_sueldos' => 'Planilla de sueldos',
        'certificacion_trabajo' => 'Certificación de trabajo',
        'revision_contratos' => 'Revisión de contratos',
        'personalizado' => 'Trámite personalizado',
    ];

    public function registrarEvento(
        Tramite $tramite,
        string $tipo,
        string $titulo,
        ?string $descripcion = null,
        ?Usuario $usuario = null,
        ?array $metadata = null
    ): TramiteEvento {
        return TramiteEvento::query()->create([
            'tramite_id' => $tramite->id,
            'tipo' => $tipo,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'metadata' => $metadata,
            'usuario_id' => $usuario?->id,
            'ocurrido_en' => now(),
        ]);
    }

    public function crearTramite(
        int $consultoraId,
        int $empresaClienteId,
        Usuario $creador,
        string $tipo,
        string $nombre,
        ?string $descripcion,
        Carbon $fechaInicio,
        ?Carbon $fechaVencimiento,
        array $colaboradorIds,
        ?array $tareasCustom = null,
        bool $notificarAsignacion = false,
        bool $esRecurrente = false,
        bool $notificarCadaPeriodo = true
    ): Tramite {
        $colaboradorIds = array_values(array_unique(array_filter($colaboradorIds)));
        if ($colaboradorIds === []) {
            throw new \InvalidArgumentException('Debe asignar al menos un colaborador responsable.');
        }

        $primerColaboradorId = $colaboradorIds[0];

        return DB::transaction(function () use (
            $consultoraId,
            $empresaClienteId,
            $creador,
            $tipo,
            $nombre,
            $descripcion,
            $fechaInicio,
            $fechaVencimiento,
            $colaboradorIds,
            $primerColaboradorId,
            $tareasCustom,
            $notificarAsignacion,
            $esRecurrente,
            $notificarCadaPeriodo
        ) {
            $tramite = Tramite::query()->create([
                'consultora_id' => $consultoraId,
                'empresa_cliente_id' => $empresaClienteId,
                'creado_por_usuario_id' => $creador->id,
                'asignado_a_colaborador_id' => $primerColaboradorId,
                'tipo' => $tipo,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'fecha_inicio' => TramiteFechas::parseSoloDia($fechaInicio),
                'fecha_vencimiento' => TramiteFechas::parseSoloDia($fechaVencimiento),
                'estado' => 'pendiente',
                'progreso_pct' => 0,
            ]);

            $tramite->colaboradoresAsignados()->sync($colaboradorIds);

            $plantilla = ($tipo === 'personalizado' && is_array($tareasCustom) && count($tareasCustom) > 0)
                ? $tareasCustom
                : (self::PLANTILLAS_TAREAS[$tipo] ?? self::PLANTILLAS_TAREAS['personalizado']);
            foreach ($plantilla as $idx => $row) {
                Tarea::query()->create([
                    'tramite_id' => $tramite->id,
                    'nombre' => $row['nombre'],
                    'estado' => 'pendiente',
                    'responsable_id' => $primerColaboradorId,
                    'orden' => $idx + 1,
                    'requiere_documento' => (bool) ($row['requiere_documento'] ?? false),
                ]);
            }

            $this->registrarEvento(
                $tramite,
                'creacion',
                'Trámite creado',
                'Se generaron '.count($plantilla).' tareas automáticas.',
                $creador,
                ['tipo' => $tipo]
            );

            $nombresCols = Colaborador::query()
                ->whereIn('id', $colaboradorIds)
                ->get()
                ->map(fn (Colaborador $c) => trim($c->nombres.' '.$c->apellidos))
                ->filter()
                ->values()
                ->all();

            $this->registrarEvento(
                $tramite,
                'asignacion',
                count($colaboradorIds) > 1 ? 'Responsables asignados' : 'Responsable asignado',
                count($nombresCols) > 0
                    ? 'Asignado a: '.implode(', ', $nombresCols).'.'
                    : 'Se asignaron colaboradores al trámite.',
                $creador,
                [
                    'colaborador_ids' => $colaboradorIds,
                    'colaborador_nombre' => implode(', ', $nombresCols),
                ]
            );

            if ($notificarAsignacion) {
                $this->notificarAsignacionTramite($tramite->fresh(['empresaCliente', 'colaboradoresAsignados']));
            }

            if ($esRecurrente) {
                app(TramiteRecurrenciaService::class)->configurarRecurrenciaEnCreacion(
                    $tramite,
                    true,
                    $fechaVencimiento ?? $tramite->fecha_vencimiento,
                    $notificarCadaPeriodo
                );
            }

            return $tramite->fresh(['tareas', 'empresaCliente', 'asignadoA', 'colaboradoresAsignados']);
        });
    }

    public function notificarAsignacionTramite(Tramite $tramite): void
    {
        $tramite->loadMissing(['empresaCliente:id,nombre,razon_social', 'colaboradoresAsignados:id,nombres,apellidos']);
        $empresaNombre = $tramite->empresaCliente?->nombre ?: $tramite->empresaCliente?->razon_social ?: 'empresa cliente';

        $colaboradores = $tramite->colaboradoresAsignados;
        if ($colaboradores->isEmpty() && $tramite->asignado_a_colaborador_id) {
            $colaboradores = Colaborador::query()->whereKey($tramite->asignado_a_colaborador_id)->get();
        }

        foreach ($colaboradores as $col) {
            Alerta::query()->create([
                'consultora_id' => $tramite->consultora_id,
                'empresa_id' => $tramite->empresa_cliente_id,
                'colaborador_asignado' => $col->id,
                'modulo' => 'tramite_asignado',
                'nivel' => 'normal',
                'titulo' => 'Trámite asignado',
                'descripcion' => "Se te asignó el trámite «{$tramite->nombre}» de {$empresaNombre}.",
                'fecha_vencimiento' => $tramite->fecha_vencimiento,
                'generada_auto' => true,
                'contexto' => [
                    'tramite_id' => $tramite->id,
                    'paths' => [
                        'colaborador' => '/colaborador/tramites/'.$tramite->id,
                        'consultora' => '/consultora/tramites/'.$tramite->id,
                    ],
                ],
            ]);
        }

        Alerta::query()->create([
            'consultora_id' => $tramite->consultora_id,
            'empresa_id' => $tramite->empresa_cliente_id,
            'colaborador_asignado' => null,
            'modulo' => 'tramite_asignado',
            'nivel' => 'normal',
            'titulo' => 'Trámite asignado a colaboradores',
            'descripcion' => "Se asignó el trámite «{$tramite->nombre}» de {$empresaNombre} a los responsables del equipo.",
            'fecha_vencimiento' => $tramite->fecha_vencimiento,
            'generada_auto' => true,
            'contexto' => [
                'tramite_id' => $tramite->id,
                'paths' => [
                    'colaborador' => '/colaborador/tramites/'.$tramite->id,
                    'consultora' => '/consultora/tramites/'.$tramite->id,
                ],
            ],
        ]);
    }

    public function notificarRecordatorioTramite(
        Tramite $tramite,
        string $tipoRecordatorio,
        string $titulo,
        string $descripcion,
        string $nivel = 'normal'
    ): array {
        $tramite->loadMissing(['empresaCliente:id,nombre,razon_social', 'colaboradoresAsignados:id,nombres,apellidos']);
        $empresaNombre = $tramite->empresaCliente?->nombre ?: $tramite->empresaCliente?->razon_social ?: 'empresa cliente';

        $colaboradores = $tramite->colaboradoresAsignados;
        if ($colaboradores->isEmpty() && $tramite->asignado_a_colaborador_id) {
            $colaboradores = Colaborador::query()->whereKey($tramite->asignado_a_colaborador_id)->get();
        }

        $contextoBase = [
            'tramite_id' => $tramite->id,
            'tipo_recordatorio' => $tipoRecordatorio,
            'periodo' => $tramite->periodo_actual,
            'paths' => [
                'colaborador' => '/colaborador/tramites/'.$tramite->id,
                'consultora' => '/consultora/tramites/'.$tramite->id,
            ],
        ];

        $alertaIds = [];

        foreach ($colaboradores as $col) {
            $alerta = Alerta::query()->create([
                'consultora_id' => $tramite->consultora_id,
                'empresa_id' => $tramite->empresa_cliente_id,
                'colaborador_asignado' => $col->id,
                'modulo' => 'tramite_recordatorio',
                'nivel' => $nivel,
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'fecha_vencimiento' => $tramite->fecha_vencimiento,
                'generada_auto' => true,
                'contexto' => $contextoBase,
            ]);
            $alertaIds[] = $alerta->id;
        }

        $alertaConsultora = Alerta::query()->create([
            'consultora_id' => $tramite->consultora_id,
            'empresa_id' => $tramite->empresa_cliente_id,
            'colaborador_asignado' => null,
            'modulo' => 'tramite_recordatorio',
            'nivel' => $nivel,
            'titulo' => $titulo,
            'descripcion' => "«{$tramite->nombre}» ({$empresaNombre}): {$descripcion}",
            'fecha_vencimiento' => $tramite->fecha_vencimiento,
            'generada_auto' => true,
            'contexto' => $contextoBase,
        ]);
        $alertaIds[] = $alertaConsultora->id;

        return $alertaIds;
    }

    public function notificarPeriodoRenovado(Tramite $tramite): void
    {
        $tramite->loadMissing(['empresaCliente:id,nombre,razon_social', 'colaboradoresAsignados:id,nombres,apellidos']);
        $empresaNombre = $tramite->empresaCliente?->nombre ?: $tramite->empresaCliente?->razon_social ?: 'empresa cliente';
        $etiqueta = app(TramiteRecurrenciaService::class)->etiquetaPeriodo($tramite->periodo_actual);

        $colaboradores = $tramite->colaboradoresAsignados;
        if ($colaboradores->isEmpty() && $tramite->asignado_a_colaborador_id) {
            $colaboradores = Colaborador::query()->whereKey($tramite->asignado_a_colaborador_id)->get();
        }

        foreach ($colaboradores as $col) {
            Alerta::query()->create([
                'consultora_id' => $tramite->consultora_id,
                'empresa_id' => $tramite->empresa_cliente_id,
                'colaborador_asignado' => $col->id,
                'modulo' => 'tramite_periodo_nuevo',
                'nivel' => 'normal',
                'titulo' => 'Nuevo período de trámite recurrente',
                'descripcion' => "Inició el período {$etiqueta} para «{$tramite->nombre}» ({$empresaNombre}). Complete las tareas y suba los documentos del mes.",
                'fecha_vencimiento' => $tramite->fecha_vencimiento,
                'generada_auto' => true,
                'contexto' => [
                    'tramite_id' => $tramite->id,
                    'periodo' => $tramite->periodo_actual,
                    'paths' => [
                        'colaborador' => '/colaborador/tramites/'.$tramite->id,
                        'consultora' => '/consultora/tramites/'.$tramite->id,
                    ],
                ],
            ]);
        }

        Alerta::query()->create([
            'consultora_id' => $tramite->consultora_id,
            'empresa_id' => $tramite->empresa_cliente_id,
            'colaborador_asignado' => null,
            'modulo' => 'tramite_periodo_nuevo',
            'nivel' => 'normal',
            'titulo' => 'Nuevo período de trámite recurrente',
            'descripcion' => "Inició el período {$etiqueta} para «{$tramite->nombre}» ({$empresaNombre}). Revise el avance del equipo.",
            'fecha_vencimiento' => $tramite->fecha_vencimiento,
            'generada_auto' => true,
            'contexto' => [
                'tramite_id' => $tramite->id,
                'periodo' => $tramite->periodo_actual,
                'paths' => [
                    'colaborador' => '/colaborador/tramites/'.$tramite->id,
                    'consultora' => '/consultora/tramites/'.$tramite->id,
                ],
            ],
        ]);
    }

    public function notificarTramiteAnulado(Tramite $tramite): void
    {
        $tramite->loadMissing(['empresaCliente:id,nombre,razon_social', 'colaboradoresAsignados:id,nombres,apellidos']);
        $empresaNombre = $tramite->empresaCliente?->nombre ?: $tramite->empresaCliente?->razon_social ?: 'empresa cliente';

        $colaboradores = $tramite->colaboradoresAsignados;
        if ($colaboradores->isEmpty() && $tramite->asignado_a_colaborador_id) {
            $colaboradores = Colaborador::query()->whereKey($tramite->asignado_a_colaborador_id)->get();
        }

        $contexto = [
            'tramite_id' => $tramite->id,
            'paths' => [
                'colaborador' => '/colaborador/tramites/'.$tramite->id,
                'consultora' => '/consultora/tramites/'.$tramite->id,
            ],
        ];

        if ($tramite->es_recurrente) {
            $tituloCol = 'Trámite recurrente anulado';
            $descCol = "Se anuló la recurrencia mensual de «{$tramite->nombre}» ({$empresaNombre}). Ya no se generarán nuevos períodos.";
            $tituloCons = 'Trámite recurrente anulado';
            $descCons = "Se anuló la recurrencia de «{$tramite->nombre}» ({$empresaNombre}).";
            $modulo = 'tramite_recurrencia_anulada';
        } else {
            $tituloCol = 'Trámite anulado';
            $descCol = "Se anuló el trámite «{$tramite->nombre}» ({$empresaNombre}). Ya no requiere gestión ni carga de documentos.";
            $tituloCons = 'Trámite anulado';
            $descCons = "Se anuló el trámite «{$tramite->nombre}» ({$empresaNombre}).";
            $modulo = 'tramite_anulado';
        }

        foreach ($colaboradores as $col) {
            Alerta::query()->create([
                'consultora_id' => $tramite->consultora_id,
                'empresa_id' => $tramite->empresa_cliente_id,
                'colaborador_asignado' => $col->id,
                'modulo' => $modulo,
                'nivel' => 'normal',
                'titulo' => $tituloCol,
                'descripcion' => $descCol,
                'fecha_vencimiento' => $tramite->fecha_vencimiento,
                'generada_auto' => true,
                'contexto' => $contexto,
            ]);
        }

        Alerta::query()->create([
            'consultora_id' => $tramite->consultora_id,
            'empresa_id' => $tramite->empresa_cliente_id,
            'colaborador_asignado' => null,
            'modulo' => $modulo,
            'nivel' => 'normal',
            'titulo' => $tituloCons,
            'descripcion' => $descCons,
            'fecha_vencimiento' => $tramite->fecha_vencimiento,
            'generada_auto' => true,
            'contexto' => $contexto,
        ]);
    }

    /** @deprecated use notificarTramiteAnulado */
    public function notificarRecurrenciaAnulada(Tramite $tramite): void
    {
        $this->notificarTramiteAnulado($tramite);
    }

    public function anularTramite(Tramite $tramite, ?Usuario $usuario = null): Tramite
    {
        if ($tramite->anulado) {
            return $tramite;
        }

        if ($tramite->estado === 'completado') {
            throw new \InvalidArgumentException('No se puede anular un trámite ya completado.');
        }

        $ahora = now();
        $tramite->anulado = true;
        $tramite->anulado_en = $ahora;

        if ($tramite->es_recurrente) {
            $tramite->recurrencia_activa = false;
            $tramite->recurrencia_anulada_en = $ahora;
        }

        $tramite->save();

        $eventoTipo = $tramite->es_recurrente ? 'recurrencia_anulada' : 'tramite_anulado';
        $eventoTitulo = $tramite->es_recurrente ? 'Recurrencia anulada' : 'Trámite anulado';
        $eventoDescripcion = $tramite->es_recurrente
            ? 'El trámite ya no se renovará mensualmente (la empresa dejó de operar con la consultora o se detuvo el trámite periódico).'
            : 'El trámite quedó archivado. Ya no requiere gestión, recordatorios ni carga de documentos.';

        $this->registrarEvento(
            $tramite,
            $eventoTipo,
            $eventoTitulo,
            $eventoDescripcion,
            $usuario,
            ['periodo' => $tramite->periodo_actual]
        );

        $this->notificarTramiteAnulado($tramite->fresh(['empresaCliente', 'colaboradoresAsignados']));

        return $tramite->fresh();
    }

    public function anularRecurrenciaTramite(Tramite $tramite, Usuario $usuario): Tramite
    {
        return $this->anularTramite($tramite, $usuario);
    }

    public function nombreColaborador(?int $colaboradorId): ?string
    {
        if (! $colaboradorId) {
            return null;
        }
        $col = Colaborador::query()->find($colaboradorId);

        return $col ? trim($col->nombres.' '.$col->apellidos) : null;
    }

    public function sincronizarEstadoTramite(Tramite $tramite): Tramite
    {
        $tramite->loadMissing('tareas');
        $tareas = $tramite->tareas;
        $total = $tareas->count();
        $completadas = $tareas->where('estado', 'completada')->count();
        $progreso = $total > 0 ? (int) round(($completadas / $total) * 100) : 0;

        $estadoAnterior = $tramite->estado;
        $nuevoEstado = $estadoAnterior;

        if ($total > 0 && $completadas === $total) {
            $nuevoEstado = 'completado';
            if (! $tramite->completado_en) {
                $tramite->completado_en = now();
            }
            if ($tramite->es_recurrente) {
                $periodo = app(TramiteRecurrenciaService::class)->etiquetaPeriodo($tramite->periodo_actual);
                $this->registrarEvento(
                    $tramite,
                    'periodo_completado',
                    'Período completado',
                    $periodo
                        ? "Se completaron todas las tareas del período {$periodo}."
                        : 'Se completaron todas las tareas del período actual.',
                    null,
                    ['periodo' => $tramite->periodo_actual]
                );
            }
        } else {
            $tramite->completado_en = null;
            $vencimiento = app(TramiteRecurrenciaService::class)->vencimientoDateTime($tramite);
            if ($vencimiento && TramiteFechas::esFechaPasada($vencimiento)) {
                $nuevoEstado = 'vencido';
            } elseif ($completadas > 0 || $tareas->contains(fn ($t) => $t->estado === 'en_proceso')) {
                $nuevoEstado = 'en_proceso';
            } else {
                $nuevoEstado = 'pendiente';
            }
        }

        $tramite->progreso_pct = $progreso;
        $tramite->estado = $nuevoEstado;
        $tramite->save();

        if ($estadoAnterior !== $nuevoEstado) {
            $this->registrarEvento(
                $tramite,
                'estado_cambio',
                'Estado actualizado',
                "El trámite pasó de «{$estadoAnterior}» a «{$nuevoEstado}».",
                null,
                ['desde' => $estadoAnterior, 'hasta' => $nuevoEstado]
            );

            if ($nuevoEstado === 'completado') {
                $this->registrarEvento($tramite, 'cierre', 'Trámite completado', 'Todas las tareas fueron finalizadas.');
            }
        }

        return $tramite->fresh();
    }

    public function completarTarea(Tarea $tarea, Usuario $usuario): Tarea
    {
        if ($tarea->estado !== 'en_proceso') {
            throw new \InvalidArgumentException('Debe iniciar la tarea antes de marcarla como completada.');
        }

        if ($tarea->requiere_documento) {
            $docsQuery = $tarea->documentos();
            $tramite = $tarea->tramite;
            if ($tramite->es_recurrente && $tramite->periodo_actual) {
                $docsQuery->where(function ($q) use ($tramite) {
                    $q->where('periodo', $tramite->periodo_actual)
                        ->orWhereNull('periodo');
                });
            }
            if ($docsQuery->count() === 0) {
                throw new \InvalidArgumentException('Debe subir al menos un documento antes de completar esta tarea.');
            }
        }

        $tarea->estado = 'completada';
        $tarea->completada_en = now();
        $tarea->save();

        $tramite = $tarea->tramite;
        $this->registrarEvento(
            $tramite,
            'tarea_completada',
            'Tarea completada',
            $tarea->nombre,
            $usuario,
            ['tarea_id' => $tarea->id]
        );

        $this->sincronizarEstadoTramite($tramite);

        return $tarea->fresh(['documentos', 'responsable']);
    }

    public function iniciarTarea(Tarea $tarea, Usuario $usuario): Tarea
    {
        if ($tarea->estado === 'completada') {
            return $tarea;
        }
        if ($tarea->estado === 'en_proceso') {
            return $tarea->fresh();
        }
        if ($tarea->estado !== 'pendiente') {
            throw new \InvalidArgumentException('La tarea no puede iniciarse en su estado actual.');
        }

        $tarea->estado = 'en_proceso';
        $tarea->save();

        $tramite = $tarea->tramite;
        $this->registrarEvento(
            $tramite,
            'tarea_iniciada',
            'Tarea iniciada',
            "Se inició la tarea «{$tarea->nombre}».",
            $usuario,
            ['tarea_id' => $tarea->id, 'tarea_nombre' => $tarea->nombre]
        );

        $this->sincronizarEstadoTramite($tramite);

        return $tarea->fresh();
    }

    public function toApiArray(Tramite $tramite, bool $detalle = false): array
    {
        $tramite->loadMissing(['empresaCliente:id,nombre,razon_social,nit', 'asignadoA:id,nombres,apellidos', 'creadoPor:id,nombre_usuario', 'colaboradoresAsignados:id,nombres,apellidos']);

        $asignados = $tramite->colaboradoresAsignados->map(fn (Colaborador $c) => [
            'id' => $c->id,
            'nombre' => trim($c->nombres.' '.$c->apellidos),
        ])->values()->all();

        if ($asignados === [] && $tramite->asignadoA) {
            $asignados = [[
                'id' => $tramite->asignadoA->id,
                'nombre' => trim($tramite->asignadoA->nombres.' '.$tramite->asignadoA->apellidos),
            ]];
        }

        $base = [
            'id' => $tramite->id,
            'empresa_cliente_id' => $tramite->empresa_cliente_id,
            'empresa_nombre' => $tramite->empresaCliente?->nombre ?: $tramite->empresaCliente?->razon_social,
            'tipo' => $tramite->tipo,
            'tipo_label' => self::TIPOS_LABEL[$tramite->tipo] ?? $tramite->tipo,
            'nombre' => $tramite->nombre,
            'descripcion' => $tramite->descripcion,
            'fecha_inicio' => $tramite->fecha_inicio?->toDateString(),
            'fecha_vencimiento' => $tramite->fecha_vencimiento?->toDateString(),
            'es_recurrente' => (bool) $tramite->es_recurrente,
            'frecuencia' => $tramite->frecuencia,
            'frecuencia_label' => $tramite->frecuencia === 'mensual' ? 'Mensual' : $tramite->frecuencia,
            'dia_vencimiento_mes' => $tramite->dia_vencimiento_mes,
            'periodo_actual' => $tramite->periodo_actual,
            'periodo_actual_label' => app(TramiteRecurrenciaService::class)->etiquetaPeriodo($tramite->periodo_actual),
            'notificar_cada_periodo' => (bool) $tramite->notificar_cada_periodo,
            'recurrencia_activa' => $tramite->es_recurrente ? (bool) $tramite->recurrencia_activa : null,
            'recurrencia_anulada_en' => optional($tramite->recurrencia_anulada_en)->toIso8601String(),
            'anulado' => (bool) $tramite->anulado,
            'anulado_en' => optional($tramite->anulado_en)->toIso8601String(),
            'estado' => $tramite->estado,
            'progreso_pct' => $tramite->progreso_pct,
            'completado_en' => optional($tramite->completado_en)->toIso8601String(),
            'asignado_a' => $asignados[0] ?? null,
            'asignados' => $asignados,
            'creado_por' => $tramite->creadoPor?->nombre_usuario,
            'creado_en' => optional($tramite->creado_en)->toIso8601String(),
            'tareas_total' => $tramite->tareas_count ?? $tramite->tareas()->count(),
            'tareas_completadas' => $tramite->tareas()->where('estado', 'completada')->count(),
        ];

        if (! $detalle) {
            return $base;
        }

        $tramite->load(['tareas.documentos', 'tareas.responsable', 'eventos.usuario']);

        $base['tareas'] = $tramite->tareas->map(fn (Tarea $t) => $this->tareaToApi($t, $tramite))->values()->all();
        $base['eventos'] = $tramite->eventos->sortBy('ocurrido_en')->values()->map(fn (TramiteEvento $e) => [
            'id' => $e->id,
            'tipo' => $e->tipo,
            'titulo' => $e->titulo,
            'descripcion' => $e->descripcion,
            'metadata' => $e->metadata,
            'usuario' => $e->usuario?->nombre_usuario,
            'ocurrido_en' => optional($e->ocurrido_en)->toIso8601String(),
        ])->all();

        return $base;
    }

    public function tareaToApi(Tarea $t, ?Tramite $tramite = null): array
    {
        $tramite = $tramite ?? $t->tramite;
        $documentos = $t->documentos;
        if ($tramite?->es_recurrente && $tramite->periodo_actual) {
            $documentos = $documentos->filter(
                fn ($d) => $d->periodo === $tramite->periodo_actual || $d->periodo === null
            );
        }

        return [
            'id' => $t->id,
            'nombre' => $t->nombre,
            'descripcion' => $t->descripcion,
            'estado' => $t->estado,
            'orden' => $t->orden,
            'requiere_documento' => $t->requiere_documento,
            'completada_en' => optional($t->completada_en)->toIso8601String(),
            'responsable' => $t->responsable ? [
                'id' => $t->responsable->id,
                'nombre' => trim($t->responsable->nombres.' '.$t->responsable->apellidos),
            ] : null,
            'documentos' => $documentos->map(fn ($d) => [
                'id' => $d->id,
                'nombre_original' => $d->nombre_original,
                'formato' => $d->formato,
                'tamano_bytes' => $d->tamano_bytes,
                'periodo' => $d->periodo,
                'fecha_subida' => optional($d->fecha_subida)->toIso8601String(),
            ])->values()->all(),
            'documentos_periodo_actual' => $documentos->count(),
        ];
    }
}
