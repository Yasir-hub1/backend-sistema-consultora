<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\Colaborador;
use App\Models\EmpresaCliente;
use App\Models\Tarea;
use App\Models\TareaDocumento;
use App\Models\Tramite;
use App\Services\TramiteAutorizacionService;
use App\Services\TramiteRecurrenciaService;
use App\Services\TramiteService;
use App\Support\TramiteFechas;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TramiteController extends ApiController
{
    public function __construct(
        private TramiteService $tramiteService,
        private TramiteRecurrenciaService $recurrenciaService
    ) {}

    public function tipos(): JsonResponse
    {
        $tipos = collect(TramiteService::TIPOS_LABEL)->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'tareas_preview' => count(TramiteService::PLANTILLAS_TAREAS[$key] ?? []),
            'tareas' => TramiteService::PLANTILLAS_TAREAS[$key] ?? [],
        ])->values();

        return $this->ok(['tipos' => $tipos]);
    }

    public function colaboradoresAsignables(Request $request): JsonResponse
    {
        $consultoraId = TramiteAutorizacionService::consultoraIdDeUsuario($request->user());
        if (! $consultoraId) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $data = $request->validate([
            'empresa_cliente_id' => ['required', 'integer'],
        ]);

        $empresaId = (int) $data['empresa_cliente_id'];
        if (! TramiteAutorizacionService::empresaAccesibleParaTramite($request->user(), $empresaId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $miColaboradorId = $request->user()->colaborador?->id;

        $rows = Colaborador::query()
            ->where('consultora_id', $consultoraId)
            ->whereHas('empresasCliente', function ($q) use ($empresaId) {
                $q->where('empresas_cliente.id', $empresaId)
                    ->where('colaborador_empresa_cliente.activo', true);
            })
            ->orderBy('nombres')
            ->orderBy('apellidos')
            ->get(['id', 'nombres', 'apellidos', 'usuario_id']);

        $colaboradores = $rows->map(fn (Colaborador $c) => [
            'id' => $c->id,
            'nombres' => $c->nombres,
            'apellidos' => $c->apellidos,
            'nombre' => trim($c->nombres.' '.$c->apellidos),
            'es_yo' => $miColaboradorId !== null && $c->id === $miColaboradorId,
        ])->values();

        return $this->ok(['colaboradores' => $colaboradores]);
    }

    public function resumen(Request $request): JsonResponse
    {
        $query = $this->queryBase($request);
        if ($query === null) {
            return $this->fail('Sin acceso.', 403);
        }

        $activos = (clone $query)->whereIn('estado', ['pendiente', 'en_proceso'])->count();
        $vencidos = (clone $query)->where('estado', 'vencido')->count();
        $completados = (clone $query)->where('estado', 'completado')->count();
        $proximos = (clone $query)->proximosAVencer()->count();

        return $this->ok([
            'activos' => $activos,
            'vencidos' => $vencidos,
            'completados' => $completados,
            'proximos_vencer' => $proximos,
        ]);
    }

    public function calendario(Request $request): JsonResponse
    {
        $query = $this->queryBase($request);
        if ($query === null) {
            return $this->fail('Sin acceso.', 403);
        }

        $desdeStr = $request->input('desde') ?? TramiteFechas::ahora()->startOfMonth()->toDateString();
        $hastaStr = $request->input('hasta') ?? TramiteFechas::ahora()->endOfMonth()->addMonth()->toDateString();
        $desde = TramiteFechas::parseSoloDia($desdeStr);
        $hasta = TramiteFechas::parseSoloDia($hastaStr)?->endOfDay();

        if ($request->filled('empresa_cliente_id')) {
            $query->where('empresa_cliente_id', (int) $request->input('empresa_cliente_id'));
        }
        if ($request->filled('asignado_a_colaborador_id') && $request->user()->tipo !== 'empresa_cliente') {
            $query->where('asignado_a_colaborador_id', (int) $request->input('asignado_a_colaborador_id'));
        }

        $baseQuery = clone $query;

        $puntuales = (clone $baseQuery)
            ->where(function ($q) {
                $q->where('es_recurrente', false)->orWhereNull('es_recurrente');
            })
            ->where('anulado', false)
            ->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '>=', $desdeStr)
            ->whereDate('fecha_vencimiento', '<=', $hastaStr)
            ->with('empresaCliente:id,nombre,razon_social')
            ->get();

        $recurrentes = (clone $baseQuery)
            ->where('es_recurrente', true)
            ->where('recurrencia_activa', true)
            ->whereNotNull('dia_vencimiento_mes')
            ->with('empresaCliente:id,nombre,razon_social')
            ->get();

        foreach ($puntuales as $tramite) {
            $this->tramiteService->sincronizarEstadoTramite($tramite);
        }

        $eventos = collect();

        foreach ($puntuales->map(fn (Tramite $t) => $t->fresh(['empresaCliente'])) as $t) {
            $eventos->push($this->mapEventoCalendario(
                $t,
                TramiteFechas::parseSoloDia($t->fecha_vencimiento),
                $t->estado,
                false,
                null
            ));
        }

        foreach ($recurrentes as $t) {
            if ($t->periodo_actual && $t->periodo_actual === TramiteFechas::periodoActual()) {
                $this->tramiteService->sincronizarEstadoTramite($t);
                $t = $t->fresh(['empresaCliente']);
            }

            foreach ($this->recurrenciaService->eventosCalendarioEnRango($t, $desde, $hasta) as $proy) {
                $eventos->push($this->mapEventoCalendario(
                    $t,
                    $proy['fecha'],
                    $proy['estado'],
                    ! $proy['es_periodo_actual'],
                    $proy['periodo']
                ));
            }
        }

        return $this->ok(['eventos' => $eventos->values()]);
    }

    private function mapEventoCalendario(
        Tramite $t,
        Carbon $fecha,
        string $estado,
        bool $esProyectado,
        ?string $periodo
    ): array {
        $estadoLabel = match ($estado) {
            'pendiente' => 'Pendiente',
            'en_proceso' => 'En proceso',
            'vencido' => 'Vencido',
            'completado' => 'Completado',
            default => $estado,
        };
        $tipoLabel = TramiteService::TIPOS_LABEL[$t->tipo] ?? $t->tipo;
        $empresaNombre = $t->empresaCliente?->nombre ?: $t->empresaCliente?->razon_social;
        $fechaTxt = $fecha->locale('es')->translatedFormat('j \d\e F \d\e Y');
        $periodoLabel = $periodo ? app(TramiteRecurrenciaService::class)->etiquetaPeriodo($periodo) : null;
        $titulo = $periodoLabel ? "{$t->nombre} · {$periodoLabel}" : $t->nombre;

        return [
            'id' => $esProyectado ? "{$t->id}-{$periodo}" : $t->id,
            'tramite_id' => $t->id,
            'title' => $titulo,
            'start' => $fecha->toDateString(),
            'end' => $fecha->toDateString(),
            'allDay' => true,
            'estado' => $estado,
            'estado_label' => $estadoLabel,
            'empresa_nombre' => $empresaNombre,
            'tipo' => $t->tipo,
            'tipo_label' => $tipoLabel,
            'es_recurrente' => (bool) $t->es_recurrente,
            'es_proyectado' => $esProyectado,
            'periodo' => $periodo,
            'periodo_label' => $periodoLabel,
            'fecha_vencimiento_label' => $fechaTxt,
            'descripcion' => "Vence el {$fechaTxt} · {$empresaNombre} · {$tipoLabel} · {$estadoLabel}"
                .($periodoLabel ? " · {$periodoLabel}" : ''),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->queryBase($request);
        if ($query === null) {
            return $this->fail('Sin acceso.', 403);
        }

        if ($request->filled('estado')) {
            if ($request->string('estado') === 'proximos') {
                $query->proximosAVencer();
            } else {
                $query->where('estado', $request->string('estado'));
            }
        }
        if ($request->filled('empresa_cliente_id')) {
            $query->where('empresa_cliente_id', (int) $request->input('empresa_cliente_id'));
        }
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->string('tipo'));
        }
        if ($request->filled('search')) {
            $s = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($s) {
                $q->where('nombre', 'ilike', $s)->orWhere('descripcion', 'ilike', $s);
            });
        }

        $perPage = min(50, max(5, (int) $request->input('per_page', 15)));
        $esProximos = $request->filled('estado') && $request->string('estado') === 'proximos';
        $paginator = $query
            ->withCount('tareas')
            ->with(['empresaCliente:id,nombre,razon_social', 'asignadoA:id,nombres,apellidos'])
            ->when(
                $esProximos,
                fn ($q) => $q->orderBy('fecha_vencimiento')->orderByDesc('creado_en'),
                fn ($q) => $q->orderByDesc('creado_en')
            )
            ->paginate($perPage);

        $paginator->getCollection()->transform(function (Tramite $t) {
            $this->recurrenciaService->reiniciarSiPeriodoCambiado($t, $this->tramiteService);
            $this->tramiteService->sincronizarEstadoTramite($t->fresh());

            return $this->tramiteService->toApiArray($t->fresh()->loadCount('tareas'), false);
        });

        return $this->ok([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()->tipo === 'empresa_cliente') {
            return $this->fail('No autorizado.', 403);
        }

        $consultoraId = TramiteAutorizacionService::consultoraIdDeUsuario($request->user());
        if (! $consultoraId) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $data = $request->validate([
            'empresa_cliente_id' => ['required', 'integer'],
            'tipo' => ['required', 'string', Rule::in(array_keys(TramiteService::TIPOS_LABEL))],
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_vencimiento' => ['required', 'date', 'after_or_equal:fecha_inicio'],
            'es_recurrente' => ['nullable', 'boolean'],
            'notificar_cada_periodo' => ['nullable', 'boolean'],
            'colaboradores_ids' => ['required', 'array', 'min:1', 'max:20'],
            'colaboradores_ids.*' => ['integer', 'distinct'],
            'asignado_a_colaborador_id' => ['nullable', 'integer'],
            'notificar_asignacion' => ['nullable', 'boolean'],
            'tareas' => ['nullable', 'array', 'min:1', 'max:25'],
            'tareas.*.nombre' => ['required_with:tareas', 'string', 'max:255'],
            'tareas.*.requiere_documento' => ['nullable', 'boolean'],
        ]);

        $colaboradorIds = array_values(array_unique(array_map('intval', $data['colaboradores_ids'] ?? [])));
        if (empty($colaboradorIds) && ! empty($data['asignado_a_colaborador_id'])) {
            $colaboradorIds = [(int) $data['asignado_a_colaborador_id']];
        }
        if ($colaboradorIds === []) {
            return $this->fail('Debe seleccionar al menos un colaborador responsable.', 422);
        }

        $empresa = EmpresaCliente::query()
            ->where('consultora_id', $consultoraId)
            ->whereKey($data['empresa_cliente_id'])
            ->first();
        if (! $empresa) {
            return $this->fail('Empresa no encontrada.', 404);
        }

        if (! TramiteAutorizacionService::empresaAccesibleParaTramite($request->user(), $empresa->id)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! empty($colaboradorIds)) {
            $validos = Colaborador::query()
                ->where('consultora_id', $consultoraId)
                ->whereIn('id', $colaboradorIds)
                ->whereHas('empresasCliente', function ($q) use ($empresa) {
                    $q->where('empresas_cliente.id', $empresa->id)
                        ->where('colaborador_empresa_cliente.activo', true);
                })
                ->pluck('id')
                ->all();
            if (count($validos) !== count($colaboradorIds)) {
                return $this->fail('Uno o más colaboradores no son válidos para esta empresa.', 422);
            }
        }

        try {
            $tramite = $this->tramiteService->crearTramite(
                $consultoraId,
                $empresa->id,
                $request->user(),
                $data['tipo'],
                $data['nombre'],
                $data['descripcion'] ?? null,
                TramiteFechas::parseSoloDia($data['fecha_inicio']),
                TramiteFechas::parseSoloDia($data['fecha_vencimiento']),
                $colaboradorIds,
                $data['tareas'] ?? null,
                (bool) ($data['notificar_asignacion'] ?? false),
                (bool) ($data['es_recurrente'] ?? false),
                (bool) ($data['notificar_cada_periodo'] ?? true)
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($this->tramiteService->toApiArray($tramite, true), 'Trámite creado.', 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tramite = Tramite::query()->find($id);
        if (! $tramite) {
            return $this->fail('Trámite no encontrado.', 404);
        }
        if (! TramiteAutorizacionService::puedeVerTramite($request->user(), $tramite)) {
            return $this->fail('Sin acceso.', 403);
        }

        $this->recurrenciaService->reiniciarSiPeriodoCambiado($tramite, $this->tramiteService);
        $tramite = $tramite->fresh();
        $this->tramiteService->sincronizarEstadoTramite($tramite);

        return $this->ok($this->tramiteService->toApiArray($tramite->fresh(), true));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tramite = Tramite::query()->find($id);
        if (! $tramite) {
            return $this->fail('Trámite no encontrado.', 404);
        }
        if (! TramiteAutorizacionService::puedeGestionarTramite($request->user(), $tramite)) {
            return $this->fail('Sin acceso.', 403);
        }

        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string', 'max:5000'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'asignado_a_colaborador_id' => ['nullable', 'integer'],
            'notificar_asignacion' => ['nullable', 'boolean'],
        ]);

        $notificar = (bool) ($data['notificar_asignacion'] ?? false);
        if (array_key_exists('asignado_a_colaborador_id', $data)) {
            $asignacionNueva = $data['asignado_a_colaborador_id'];
            if ($asignacionNueva) {
                $col = Colaborador::query()
                    ->where('consultora_id', $tramite->consultora_id)
                    ->whereKey($asignacionNueva)
                    ->first();
                if (! $col) {
                    return $this->fail('Colaborador no válido.', 422);
                }
            }
            if ($tramite->asignado_a_colaborador_id != $asignacionNueva) {
                $tramite->asignado_a_colaborador_id = $asignacionNueva;
                $nombreCol = $this->tramiteService->nombreColaborador($asignacionNueva);
                $this->tramiteService->registrarEvento(
                    $tramite,
                    'asignacion',
                    'Responsable actualizado',
                    $asignacionNueva
                        ? ($nombreCol ? "Se asignó a {$nombreCol}." : 'Se asignó un nuevo responsable.')
                        : 'Se quitó la asignación.',
                    $request->user(),
                    [
                        'colaborador_id' => $asignacionNueva,
                        'colaborador_nombre' => $nombreCol,
                    ]
                );
            }
        }

        if (isset($data['nombre'])) {
            $tramite->nombre = $data['nombre'];
        }
        if (array_key_exists('descripcion', $data)) {
            $tramite->descripcion = $data['descripcion'];
        }
        if (array_key_exists('fecha_vencimiento', $data)) {
            $tramite->fecha_vencimiento = $data['fecha_vencimiento']
                ? TramiteFechas::parseSoloDia($data['fecha_vencimiento'])
                : null;
        }

        $tramite->save();
        $this->tramiteService->sincronizarEstadoTramite($tramite);

        if ($notificar && $tramite->asignado_a_colaborador_id) {
            $this->tramiteService->notificarAsignacionTramite($tramite->fresh(['empresaCliente', 'asignadoA']));
        }

        return $this->ok($this->tramiteService->toApiArray($tramite->fresh(), true), 'Trámite actualizado.');
    }

    public function anularRecurrencia(Request $request, int $id): JsonResponse
    {
        return $this->anularTramite($request, $id);
    }

    public function anularTramite(Request $request, int $id): JsonResponse
    {
        $tramite = Tramite::query()->find($id);
        if (! $tramite) {
            return $this->fail('Trámite no encontrado.', 404);
        }
        if (! TramiteAutorizacionService::puedeGestionarTramite($request->user(), $tramite)) {
            return $this->fail('Sin acceso.', 403);
        }

        try {
            $tramite = $this->tramiteService->anularTramite($tramite, $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $mensaje = $tramite->es_recurrente
            ? 'Recurrencia mensual anulada. Ya no se generarán nuevos períodos.'
            : 'Trámite anulado. Ya no requiere gestión ni recordatorios.';

        return $this->ok(
            $this->tramiteService->toApiArray($tramite->fresh(), true),
            $mensaje
        );
    }

    public function iniciarTarea(Request $request, int $tramiteId, int $tareaId): JsonResponse
    {
        $tramite = $this->resolverTramiteGestion($request, $tramiteId);
        if ($tramite instanceof JsonResponse) {
            return $tramite;
        }

        $tarea = $tramite->tareas()->whereKey($tareaId)->first();
        if (! $tarea) {
            return $this->fail('Tarea no encontrada.', 404);
        }

        $tarea = $this->tramiteService->iniciarTarea($tarea, $request->user());

        return $this->ok($this->tramiteService->tareaToApi($tarea), 'Tarea en proceso.');
    }

    public function completarTarea(Request $request, int $tramiteId, int $tareaId): JsonResponse
    {
        $tramite = $this->resolverTramiteGestion($request, $tramiteId);
        if ($tramite instanceof JsonResponse) {
            return $tramite;
        }

        $tarea = $tramite->tareas()->whereKey($tareaId)->first();
        if (! $tarea) {
            return $this->fail('Tarea no encontrada.', 404);
        }

        try {
            $tarea = $this->tramiteService->completarTarea($tarea, $request->user());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok([
            'tarea' => $this->tramiteService->tareaToApi($tarea),
            'tramite' => $this->tramiteService->toApiArray($tramite->fresh(), true),
        ], 'Tarea completada.');
    }

    public function subirDocumentoTarea(Request $request, int $tramiteId, int $tareaId): JsonResponse
    {
        $tramite = $this->resolverTramiteGestion($request, $tramiteId);
        if ($tramite instanceof JsonResponse) {
            return $tramite;
        }

        $tarea = $tramite->tareas()->whereKey($tareaId)->first();
        if (! $tarea) {
            return $this->fail('Tarea no encontrada.', 404);
        }

        if ($tarea->estado !== 'en_proceso') {
            return $this->fail('Debe iniciar la tarea antes de subir documentos.', 422);
        }

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'tipo' => ['nullable', 'string', 'max:64'],
        ]);

        $file = $request->file('archivo');
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()) ?: 'documento';
        $ext = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        $filename = sprintf('tarea_%d_%s_%s', $tareaId, now()->format('YmdHis'), $safeName);
        $directory = sprintf('tramites/%d/tareas/%d', $tramiteId, $tareaId);

        $path = $file->storeAs($directory, $filename, 'local');
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return $this->fail('No se pudo guardar el archivo en el servidor.', 500);
        }

        $doc = TareaDocumento::query()->create([
            'tarea_id' => $tarea->id,
            'periodo' => $tramite->es_recurrente ? $tramite->periodo_actual : null,
            'tipo' => $data['tipo'] ?? 'adjunto',
            'nombre_original' => $file->getClientOriginalName(),
            'ruta_archivo' => $path,
            'formato' => $ext,
            'tamano_bytes' => $file->getSize(),
            'subido_por_usuario_id' => $request->user()->id,
            'fecha_subida' => now(),
        ]);

        $this->tramiteService->registrarEvento(
            $tramite,
            'documento_subido',
            'Documento adjunto',
            "Se cargó «{$doc->nombre_original}» en la tarea «{$tarea->nombre}».",
            $request->user(),
            [
                'tarea_id' => $tarea->id,
                'tarea_nombre' => $tarea->nombre,
                'documento_id' => $doc->id,
                'nombre_archivo' => $doc->nombre_original,
                'formato' => $doc->formato,
            ]
        );

        return $this->ok([
            'id' => $doc->id,
            'nombre_original' => $doc->nombre_original,
            'formato' => $doc->formato,
            'tamano_bytes' => $doc->tamano_bytes,
            'fecha_subida' => optional($doc->fecha_subida)->toIso8601String(),
        ], 'Documento guardado.', 201);
    }

    public function descargarDocumentoTarea(Request $request, int $tramiteId, int $tareaId, int $documentoId): StreamedResponse|JsonResponse
    {
        $tramite = Tramite::query()->find($tramiteId);
        if (! $tramite || ! TramiteAutorizacionService::puedeVerTramite($request->user(), $tramite)) {
            return $this->fail('Sin acceso.', 403);
        }

        $doc = TareaDocumento::query()
            ->where('tarea_id', $tareaId)
            ->whereHas('tarea', fn ($q) => $q->where('tramite_id', $tramiteId))
            ->whereKey($documentoId)
            ->first();

        if (! $doc) {
            return $this->fail('Documento no encontrado.', 404);
        }

        if (! Storage::disk('local')->exists($doc->ruta_archivo)) {
            return $this->fail('El archivo no está disponible en el servidor.', 404);
        }

        $mime = match ($doc->formato) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        return Storage::disk('local')->download($doc->ruta_archivo, $doc->nombre_original, [
            'Content-Type' => $mime,
        ]);
    }

    private function resolverTramiteGestion(Request $request, int $id): Tramite|JsonResponse
    {
        $tramite = Tramite::query()->find($id);
        if (! $tramite) {
            return $this->fail('Trámite no encontrado.', 404);
        }
        if (! TramiteAutorizacionService::puedeGestionarTramite($request->user(), $tramite)) {
            return $this->fail('Sin acceso.', 403);
        }

        if ($tramite->anulado || ($tramite->es_recurrente && ! $tramite->recurrencia_activa)) {
            return $this->fail(
                'Este trámite fue anulado. Ya no admite gestión ni carga de documentos.',
                422
            );
        }

        return $tramite;
    }

    private function queryBase(Request $request): ?\Illuminate\Database\Eloquent\Builder
    {
        $u = $request->user();

        if ($u->tipo === 'consultora' && ($ec = $u->empresaConsultoraTitular)) {
            return Tramite::query()->where('consultora_id', $ec->id);
        }

        if ($u->tipo === 'colaborador' && ($c = $u->colaborador)) {
            $empresaIds = $c->empresasCliente()->wherePivot('activo', true)->pluck('empresas_cliente.id');

            return Tramite::query()
                ->where('consultora_id', $c->consultora_id)
                ->whereIn('empresa_cliente_id', $empresaIds);
        }

        if ($u->tipo === 'empresa_cliente' && ($emp = $u->empresaClienteComoUsuario)) {
            return Tramite::query()->where('empresa_cliente_id', $emp->id);
        }

        return null;
    }
}
