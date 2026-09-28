<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\DeclaracionAguinaldo;
use App\Models\DeclaracionMensual;
use App\Models\EmpresaCliente;
use App\Models\EmpresaClienteOtroDocumento;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class ReporteDeclaracionController extends ApiController
{
    private function consultoraId(Request $request): ?int
    {
        $u = $request->user();

        return $u->empresaConsultoraTitular?->id
            ?? $u->colaborador?->consultora_id;
    }

    /**
     * null: la consultora titular ve toda su cartera.
     * lista: el colaborador solo ve empresas asignadas.
     *
     * @return list<int>|null
     */
    private function idsEmpresasVisibles(Request $request, int $consultoraId): ?array
    {
        $titular = $request->user()->empresaConsultoraTitular;
        if ($titular && (int) $titular->id === $consultoraId) {
            return null;
        }

        $colaborador = $request->user()->colaborador;
        if (! $colaborador || (int) $colaborador->consultora_id !== $consultoraId) {
            return [];
        }

        return $colaborador->empresasCliente()
            ->where('empresas_cliente.consultora_id', $consultoraId)
            ->wherePivot('activo', true)
            ->pluck('empresas_cliente.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function limitarEmpresas(mixed $query, ?array $ids, string $column = 'empresa_cliente_id'): void
    {
        if ($ids === null) {
            return;
        }

        $query->whereIn($column, $ids === [] ? [-1] : $ids);
    }

    public function empresas(Request $request): JsonResponse
    {
        $consultoraId = $this->consultoraId($request);
        if (! $consultoraId) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $rows = EmpresaCliente::query()
            ->where('consultora_id', $consultoraId);
        $this->limitarEmpresas($rows, $this->idsEmpresasVisibles($request, $consultoraId), 'id');
        $rows = $rows
            ->orderBy('nombre')
            ->orderBy('razon_social')
            ->get(['id', 'nombre', 'razon_social', 'nit'])
            ->map(fn (EmpresaCliente $e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'razon_social' => $e->razon_social,
                'nit' => $e->nit,
            ])
            ->values()
            ->all();

        return $this->ok($rows);
    }

    public function index(Request $request): JsonResponse
    {
        $consultoraId = $this->consultoraId($request);
        if (! $consultoraId) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $request->validate([
            'mes_gestion' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'empresa_cliente_id' => ['nullable', 'integer'],
            'modulo' => ['nullable', 'in:afp,caja,ministerio'],
            'tipo_declaracion' => ['nullable', 'in:mensual,aguinaldo,todos,otros_documentos'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $tipo = (string) ($request->query('tipo_declaracion') ?: 'mensual');
        $perPage = (int) $request->get('per_page', 50);
        $page = max((int) $request->get('page', 1), 1);
        $idsVisibles = $this->idsEmpresasVisibles($request, $consultoraId);

        if ($tipo === 'mensual') {
            $q = DeclaracionMensual::query()
                ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId))
                ->with(['empresaCliente:id,nombre,razon_social,nit'])
                ->when($idsVisibles !== null, fn ($q) => $q->whereIn('empresa_cliente_id', $idsVisibles === [] ? [-1] : $idsVisibles));
            $this->limitarEmpresas($q, $idsVisibles);

            if ($mg = $request->string('mes_gestion')->toString()) {
                [$anio, $mes] = array_map('intval', explode('-', $mg));
                $q->where('anio', $anio)->where('mes', $mes);
            }
            if ($modulo = $request->query('modulo')) {
                $q->where('modulo', $modulo);
            }
            if ($empresaId = $request->query('empresa_cliente_id')) {
                $q->where('empresa_cliente_id', (int) $empresaId);
            }

            $p = $q->orderByDesc('anio')
                ->orderByDesc('mes')
                ->orderBy('modulo')
                ->paginate($perPage);

            return $this->ok([
                'data' => collect($p->items())->map(fn (DeclaracionMensual $d) => $this->serializarMensual($d))->all(),
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
            ]);
        }

        if ($tipo === 'aguinaldo') {
            $q = DeclaracionAguinaldo::query()
                ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId))
                ->with(['empresaCliente:id,nombre,razon_social,nit'])
                ->when($idsVisibles !== null, fn ($q) => $q->whereIn('empresa_cliente_id', $idsVisibles === [] ? [-1] : $idsVisibles));
            $this->limitarEmpresas($q, $idsVisibles);
            if ($anio = $request->query('anio')) {
                $q->where('anio', (int) $anio);
            }
            if ($empresaId = $request->query('empresa_cliente_id')) {
                $q->where('empresa_cliente_id', (int) $empresaId);
            }

            $p = $q->orderByDesc('anio')->paginate($perPage);

            return $this->ok([
                'data' => collect($p->items())->map(fn (DeclaracionAguinaldo $d) => $this->serializarAguinaldo($d))->all(),
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
            ]);
        }

        if ($tipo === 'otros_documentos') {
            $q = EmpresaClienteOtroDocumento::query()
                ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId))
                ->with(['empresaCliente:id,nombre,razon_social,nit'])
                ->when($idsVisibles !== null, fn ($q) => $q->whereIn('empresa_cliente_id', $idsVisibles === [] ? [-1] : $idsVisibles));
            $this->limitarEmpresas($q, $idsVisibles);

            if ($mg = $request->string('mes_gestion')->toString()) {
                [$anio, $mes] = array_map('intval', explode('-', $mg));
                $start = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();
                $end = (clone $start)->endOfMonth();
                $q->whereBetween('fecha_subida', [$start, $end]);
            }
            if ($empresaId = $request->query('empresa_cliente_id')) {
                $q->where('empresa_cliente_id', (int) $empresaId);
            }

            $p = $q->orderByDesc('fecha_subida')->orderByDesc('id')->paginate($perPage);

            return $this->ok([
                'data' => collect($p->items())->map(fn (EmpresaClienteOtroDocumento $d) => $this->serializarOtroDocumento($d))->all(),
                'current_page' => $p->currentPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
            ]);
        }

        $mensuales = DeclaracionMensual::query()
            ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId))
            ->with(['empresaCliente:id,nombre,razon_social,nit'])
            ->when($idsVisibles !== null, fn ($q) => $q->whereIn('empresa_cliente_id', $idsVisibles === [] ? [-1] : $idsVisibles))
            ->when($request->string('mes_gestion')->toString() !== '', function ($q) use ($request) {
                [$anio, $mes] = array_map('intval', explode('-', $request->string('mes_gestion')->toString()));
                $q->where('anio', $anio)->where('mes', $mes);
            })
            ->when($request->query('modulo'), fn ($q, $m) => $q->where('modulo', $m))
            ->when($request->query('empresa_cliente_id'), fn ($q, $eid) => $q->where('empresa_cliente_id', (int) $eid))
            ->get()
            ->map(fn (DeclaracionMensual $d) => $this->serializarMensual($d));

        $aguinaldos = DeclaracionAguinaldo::query()
            ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId))
            ->with(['empresaCliente:id,nombre,razon_social,nit'])
            ->when($idsVisibles !== null, fn ($q) => $q->whereIn('empresa_cliente_id', $idsVisibles === [] ? [-1] : $idsVisibles))
            ->when($request->query('anio'), fn ($q, $a) => $q->where('anio', (int) $a))
            ->when($request->query('empresa_cliente_id'), fn ($q, $eid) => $q->where('empresa_cliente_id', (int) $eid))
            ->get()
            ->map(fn (DeclaracionAguinaldo $d) => $this->serializarAguinaldo($d));

        $otrosDocs = EmpresaClienteOtroDocumento::query()
            ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId))
            ->with(['empresaCliente:id,nombre,razon_social,nit'])
            ->when($idsVisibles !== null, fn ($q) => $q->whereIn('empresa_cliente_id', $idsVisibles === [] ? [-1] : $idsVisibles))
            ->when($request->string('mes_gestion')->toString() !== '', function ($q) use ($request) {
                [$anio, $mes] = array_map('intval', explode('-', $request->string('mes_gestion')->toString()));
                $start = Carbon::createFromDate($anio, $mes, 1)->startOfMonth();
                $end = (clone $start)->endOfMonth();
                $q->whereBetween('fecha_subida', [$start, $end]);
            })
            ->when($request->query('empresa_cliente_id'), fn ($q, $eid) => $q->where('empresa_cliente_id', (int) $eid))
            ->get()
            ->map(fn (EmpresaClienteOtroDocumento $d) => $this->serializarOtroDocumento($d));

        $rows = $mensuales
            ->concat($aguinaldos)
            ->concat($otrosDocs)
            ->sortByDesc(fn (array $r) => $r['fecha_subida'] ?? '')
            ->values();

        $p = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page
        );

        return $this->ok([
            'data' => $p->items(),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
        ]);
    }

    public function vistaPrevia(Request $request, int $id): BinaryFileResponse|JsonResponse|StreamedResponse
    {
        $doc = $this->resolverDocumento($request, $id);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        if ($doc instanceof EmpresaClienteOtroDocumento) {
            if (! Storage::disk('local')->exists($doc->ruta_archivo)) {
                return $this->fail('Archivo no disponible', 404);
            }

            return Storage::disk('local')->response($doc->ruta_archivo, $doc->nombre_original, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$this->safeName($doc->nombre_original).'"',
            ]);
        }

        if (! $doc instanceof DeclaracionMensual && ! $doc instanceof DeclaracionAguinaldo) {
            return $this->fail('Tipo de documento no soportado', 422);
        }

        if (! is_string($doc->ruta_archivo) || $doc->ruta_archivo === '') {
            return $this->fail('Esta declaración no tiene PDF.', 404);
        }

        $abs = Storage::disk('local')->path($doc->ruta_archivo);
        if (! is_readable($abs)) {
            return $this->fail('Archivo no disponible', 404);
        }

        return response()->file($abs, [
            'Content-Disposition' => 'inline; filename="'.$this->safeName($doc->nombre_original).'"',
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function descargar(Request $request, int $id): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $doc = $this->resolverDocumento($request, $id);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        if ($doc instanceof EmpresaClienteOtroDocumento) {
            if (! Storage::disk('local')->exists($doc->ruta_archivo)) {
                return $this->fail('Archivo no disponible', 404);
            }

            return Storage::disk('local')->download($doc->ruta_archivo, $doc->nombre_original, [
                'Content-Type' => 'application/pdf',
            ]);
        }

        if (! $doc instanceof DeclaracionMensual && ! $doc instanceof DeclaracionAguinaldo) {
            return $this->fail('Tipo de documento no soportado', 422);
        }

        if (! is_string($doc->ruta_archivo) || $doc->ruta_archivo === '') {
            return $this->fail('Esta declaración no tiene PDF.', 404);
        }

        $abs = Storage::disk('local')->path($doc->ruta_archivo);
        if (! is_readable($abs)) {
            return $this->fail('Archivo no disponible', 404);
        }

        return response()->download($abs, $doc->nombre_original);
    }

    public function exportarPdf(Request $request): BinaryFileResponse|JsonResponse
    {
        $consultoraId = $this->consultoraId($request);
        if (! $consultoraId) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $request->validate([
            'mes_gestion' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'modulo' => ['nullable', 'in:afp,caja,ministerio'],
        ]);

        [$anio, $mes] = array_map('intval', explode('-', (string) $request->input('mes_gestion')));

        $q = DeclaracionMensual::query()
            ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId))
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->where('formato', 'pdf');
        $this->limitarEmpresas($q, $this->idsEmpresasVisibles($request, $consultoraId));

        if ($modulo = $request->query('modulo')) {
            $q->where('modulo', $modulo);
        }

        $rows = $q->orderBy('modulo')->orderBy('empresa_cliente_id')->get();
        if ($rows->isEmpty()) {
            return $this->fail('No hay PDFs para consolidar con esos filtros.', 422);
        }

        $inputFiles = [];
        foreach ($rows as $row) {
            if (! is_string($row->ruta_archivo) || $row->ruta_archivo === '') {
                return $this->fail('Uno o más archivos no están disponibles en almacenamiento.', 422);
            }
            $abs = Storage::disk('local')->path($row->ruta_archivo);
            if (! is_readable($abs)) {
                return $this->fail('Uno o más archivos no están disponibles en almacenamiento.', 422);
            }
            $inputFiles[] = $abs;
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'reporte_decl_');
        if ($tmpBase === false) {
            return $this->fail('No se pudo crear archivo temporal.', 500);
        }
        @unlink($tmpBase);
        $out = $tmpBase.'.pdf';

        $ok = $this->mergePdfs($inputFiles, $out);
        if (! $ok['success']) {
            @unlink($out);

            return $this->fail($ok['message'], 500);
        }

        $modLabel = $request->query('modulo') ? strtoupper((string) $request->query('modulo')) : 'TODOS';
        $downloadName = sprintf('reporte_%04d-%02d_%s.pdf', $anio, $mes, $modLabel);

        return response()->download($out, $downloadName)->deleteFileAfterSend(true);
    }

    private function resolverDocumento(Request $request, int $id): DeclaracionMensual|DeclaracionAguinaldo|EmpresaClienteOtroDocumento|JsonResponse
    {
        $consultoraId = $this->consultoraId($request);
        if (! $consultoraId) {
            return $this->fail('Sin consultora asociada.', 403);
        }
        $idsVisibles = $this->idsEmpresasVisibles($request, $consultoraId);

        $tipo = (string) ($request->query('tipo_declaracion') ?: 'mensual');
        if ($tipo === 'otros_documentos') {
            $doc = EmpresaClienteOtroDocumento::query()
                ->whereKey($id)
                ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId));
            $this->limitarEmpresas($doc, $idsVisibles);
            $doc = $doc->first();
            if (! $doc) {
                return $this->fail('Documento no encontrado', 404);
            }

            return $doc;
        }

        if ($tipo === 'aguinaldo') {
            $doc = DeclaracionAguinaldo::query()
                ->whereKey($id)
                ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId));
            $this->limitarEmpresas($doc, $idsVisibles);
            $doc = $doc->first();
            if (! $doc) {
                return $this->fail('Declaración de aguinaldo no encontrada', 404);
            }

            return $doc;
        }

        $doc = DeclaracionMensual::query()
            ->whereKey($id)
            ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultoraId));
        $this->limitarEmpresas($doc, $idsVisibles);
        $doc = $doc->first();

        if (! $doc) {
            return $this->fail('Declaración no encontrada', 404);
        }

        return $doc;
    }

    private function safeName(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', $name);
    }

    /**
     * Une PDFs usando pdfunite (poppler-utils).
     *
     * @param  array<int, string>  $inputFiles
     * @return array{success: bool, message: string}
     */
    private function mergePdfs(array $inputFiles, string $out): array
    {
        $cmd = array_merge(['pdfunite'], $inputFiles, [$out]);
        $proc = new Process($cmd);
        $proc->setTimeout(120);
        $proc->run();

        if ($proc->isSuccessful() && is_file($out) && filesize($out) > 0) {
            return ['success' => true, 'message' => 'ok'];
        }

        return [
            'success' => false,
            'message' => 'No se pudo consolidar PDFs con pdfunite. Verifica poppler-utils y permisos de ejecución.',
        ];
    }

    private function serializarMensual(DeclaracionMensual $d): array
    {
        return [
            'id' => $d->id,
            'tipo_declaracion' => 'mensual',
            'empresa_id' => $d->empresa_cliente_id,
            'empresa_nombre' => $d->empresaCliente?->nombre ?: $d->empresaCliente?->razon_social,
            'empresa_nit' => $d->empresaCliente?->nit,
            'mes_gestion' => sprintf('%04d-%02d', $d->anio, $d->mes),
            'periodo_label' => sprintf('%04d-%02d', $d->anio, $d->mes),
            'modulo' => $d->modulo,
            'nombre_original' => $d->nombre_original,
            'formato' => $d->formato,
            'tamano_bytes' => $d->tamano_bytes,
            'fecha_subida' => $d->fecha_subida?->toIso8601String(),
        ];
    }

    private function serializarAguinaldo(DeclaracionAguinaldo $d): array
    {
        return [
            'id' => $d->id,
            'tipo_declaracion' => 'aguinaldo',
            'empresa_id' => $d->empresa_cliente_id,
            'empresa_nombre' => $d->empresaCliente?->nombre ?: $d->empresaCliente?->razon_social,
            'empresa_nit' => $d->empresaCliente?->nit,
            'mes_gestion' => (string) $d->anio,
            'periodo_label' => 'Gestión '.$d->anio,
            'modulo' => 'aguinaldo',
            'nombre_original' => $d->nombre_original,
            'formato' => $d->formato,
            'tamano_bytes' => $d->tamano_bytes,
            'fecha_subida' => $d->fecha_subida?->toIso8601String(),
        ];
    }

    private function serializarOtroDocumento(EmpresaClienteOtroDocumento $d): array
    {
        $fecha = $d->fecha_subida;

        return [
            'id' => $d->id,
            'tipo_declaracion' => 'otros_documentos',
            'empresa_id' => $d->empresa_cliente_id,
            'empresa_nombre' => $d->empresaCliente?->nombre ?: $d->empresaCliente?->razon_social,
            'empresa_nit' => $d->empresaCliente?->nit,
            'mes_gestion' => $fecha ? sprintf('%04d-%02d', $fecha->year, $fecha->month) : null,
            'periodo_label' => $fecha
                ? $fecha->copy()->locale('es')->translatedFormat('M Y')
                : '—',
            'modulo' => 'otros_documentos',
            'descripcion' => $d->descripcion,
            'nombre_original' => $d->nombre_original,
            'formato' => $d->formato,
            'tamano_bytes' => $d->tamano_bytes,
            'fecha_subida' => $fecha?->toIso8601String(),
        ];
    }
}
