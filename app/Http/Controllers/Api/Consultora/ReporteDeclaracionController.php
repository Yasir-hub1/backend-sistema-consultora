<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\DeclaracionMensual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class ReporteDeclaracionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $consultora = $request->user()->empresaConsultoraTitular;
        if (! $consultora) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $request->validate([
            'mes_gestion' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'modulo' => ['nullable', 'in:afp,caja,ministerio'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $q = DeclaracionMensual::query()
            ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultora->id))
            ->with(['empresaCliente:id,nombre,razon_social,nit']);

        if ($mg = $request->string('mes_gestion')->toString()) {
            [$anio, $mes] = array_map('intval', explode('-', $mg));
            $q->where('anio', $anio)->where('mes', $mes);
        }
        if ($modulo = $request->query('modulo')) {
            $q->where('modulo', $modulo);
        }

        $p = $q->orderByDesc('anio')
            ->orderByDesc('mes')
            ->orderBy('modulo')
            ->paginate((int) $request->get('per_page', 50));

        return $this->ok([
            'data' => collect($p->items())->map(fn (DeclaracionMensual $d) => $this->serializar($d))->all(),
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
        ]);
    }

    public function vistaPrevia(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $doc = $this->resolverDeclaracion($request, $id);
        if (! $doc instanceof DeclaracionMensual) {
            return $doc;
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
        $doc = $this->resolverDeclaracion($request, $id);
        if (! $doc instanceof DeclaracionMensual) {
            return $doc;
        }

        $abs = Storage::disk('local')->path($doc->ruta_archivo);
        if (! is_readable($abs)) {
            return $this->fail('Archivo no disponible', 404);
        }

        return response()->download($abs, $doc->nombre_original);
    }

    public function exportarPdf(Request $request): BinaryFileResponse|JsonResponse
    {
        $consultora = $request->user()->empresaConsultoraTitular;
        if (! $consultora) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $request->validate([
            'mes_gestion' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'modulo' => ['nullable', 'in:afp,caja,ministerio'],
        ]);

        [$anio, $mes] = array_map('intval', explode('-', (string) $request->input('mes_gestion')));

        $q = DeclaracionMensual::query()
            ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultora->id))
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->where('formato', 'pdf');

        if ($modulo = $request->query('modulo')) {
            $q->where('modulo', $modulo);
        }

        $rows = $q->orderBy('modulo')->orderBy('empresa_cliente_id')->get();
        if ($rows->isEmpty()) {
            return $this->fail('No hay PDFs para consolidar con esos filtros.', 422);
        }

        $inputFiles = [];
        foreach ($rows as $row) {
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

    private function resolverDeclaracion(Request $request, int $id): DeclaracionMensual|JsonResponse
    {
        $consultora = $request->user()->empresaConsultoraTitular;
        if (! $consultora) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $doc = DeclaracionMensual::query()
            ->whereKey($id)
            ->whereHas('empresaCliente', fn ($w) => $w->where('consultora_id', $consultora->id))
            ->first();

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

    private function serializar(DeclaracionMensual $d): array
    {
        return [
            'id' => $d->id,
            'empresa_id' => $d->empresa_cliente_id,
            'empresa_nombre' => $d->empresaCliente?->nombre ?: $d->empresaCliente?->razon_social,
            'empresa_nit' => $d->empresaCliente?->nit,
            'mes_gestion' => sprintf('%04d-%02d', $d->anio, $d->mes),
            'modulo' => $d->modulo,
            'nombre_original' => $d->nombre_original,
            'formato' => $d->formato,
            'tamano_bytes' => $d->tamano_bytes,
            'fecha_subida' => $d->fecha_subida?->toIso8601String(),
        ];
    }
}
