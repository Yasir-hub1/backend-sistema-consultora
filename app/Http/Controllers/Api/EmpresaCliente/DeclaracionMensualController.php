<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use App\Models\DeclaracionMensual;
use App\Models\EmpresaCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class DeclaracionMensualController extends ApiController
{
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    private function empresa(Request $request): ?EmpresaCliente
    {
        return $request->user()->empresaClienteComoUsuario;
    }

    public function index(Request $request): JsonResponse
    {
        $emp = $this->empresa($request);
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $items = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $emp->id)
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->orderBy('modulo')
            ->limit(60)
            ->get()
            ->map(fn (DeclaracionMensual $d) => $this->serializar($d));

        return $this->ok(['items' => $items]);
    }

    public function descargar(Request $request, int $id): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $emp = $this->empresa($request);
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $dec = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $emp->id)
            ->whereKey($id)
            ->first();
        if (! $dec) {
            return $this->fail('No encontrada', 404);
        }
        if (! Storage::disk('local')->exists($dec->ruta_archivo)) {
            return $this->fail('Archivo no disponible', 404);
        }

        return response()->download(
            Storage::disk('local')->path($dec->ruta_archivo),
            $dec->nombre_original
        );
    }

    public function vistaPrevia(Request $request, int $id): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $emp = $this->empresa($request);
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $dec = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $emp->id)
            ->whereKey($id)
            ->first();
        if (! $dec) {
            return $this->fail('No encontrada', 404);
        }
        if (! Storage::disk('local')->exists($dec->ruta_archivo)) {
            return $this->fail('Archivo no disponible', 404);
        }

        $path = Storage::disk('local')->path($dec->ruta_archivo);
        $mime = mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$dec->nombre_original.'"',
        ]);
    }

    /**
     * Descarga varias declaraciones en un ZIP. Body JSON: { "meses": ["2026-01", "2026-07"] }
     */
    public function descargarZip(Request $request): BinaryFileResponse|JsonResponse
    {
        $emp = $this->empresa($request);
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $request->validate([
            'meses' => ['required', 'array', 'min:1', 'max:36'],
            'meses.*' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $meses = collect($request->input('meses'))
            ->map(fn ($m) => (string) $m)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $declaraciones = [];
        $faltantes = [];

        foreach ($meses as $mg) {
            $parts = explode('-', $mg);
            $anio = (int) $parts[0];
            $mes = (int) $parts[1];
            $decs = DeclaracionMensual::query()
                ->where('empresa_cliente_id', $emp->id)
                ->where('anio', $anio)
                ->where('mes', $mes)
                ->get();

            if ($decs->isEmpty()) {
                $faltantes[] = $mg;
                continue;
            }
            foreach ($decs as $dec) {
                if (! Storage::disk('local')->exists($dec->ruta_archivo)) {
                    $faltantes[] = $mg.'-'.$dec->modulo;
                    continue;
                }
                $declaraciones[] = $dec;
            }
        }

        if ($faltantes !== []) {
            return $this->fail(
                'No hay declaración cargada para: '.implode(', ', $faltantes).'.',
                422
            );
        }

        if ($declaraciones === []) {
            return $this->fail('No hay archivos para descargar.', 422);
        }

        if (count($declaraciones) === 1) {
            $d = $declaraciones[0];

            return response()->download(
                Storage::disk('local')->path($d->ruta_archivo),
                $d->nombre_original
            );
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'decl_zip_');
        if ($tmpBase === false) {
            return $this->fail('No se pudo preparar el archivo temporal.', 500);
        }
        @unlink($tmpBase);
        $tmpZip = $tmpBase.'.zip';

        $zip = new ZipArchive;
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->fail('No se pudo crear el archivo comprimido.', 500);
        }

        foreach ($declaraciones as $dec) {
            $abs = Storage::disk('local')->path($dec->ruta_archivo);
            if (! is_readable($abs)) {
                $zip->close();
                @unlink($tmpZip);

                return $this->fail('Archivo no legible en almacenamiento.', 500);
            }
            $entryName = sprintf(
                '%04d-%02d_%s_%s',
                $dec->anio,
                $dec->mes,
                strtoupper((string) $dec->modulo),
                $this->nombreSeguroZip($dec->nombre_original)
            );
            $zip->addFile($abs, $entryName);
        }

        $zip->close();

        $slugNit = preg_replace('/\W+/', '_', (string) ($emp->nit ?? 'empresa')) ?: 'empresa';
        $zipDownloadName = 'declaraciones_personal_'.$slugNit.'_'.now()->format('Y-m-d_His').'.zip';

        return response()->download($tmpZip, $zipDownloadName)->deleteFileAfterSend(true);
    }

    private function nombreSeguroZip(string $original): string
    {
        $base = basename($original);
        $base = preg_replace('/[^a-zA-Z0-9._\-áéíóúÁÉÍÓÚñÑüÜ ]+/u', '_', $base) ?? $base;
        $base = trim($base) !== '' ? trim($base) : 'archivo';

        return $base;
    }

    private function serializar(DeclaracionMensual $d): array
    {
        $mes = (int) $d->mes;
        $mesNombre = self::MESES[$mes] ?? 'mes';

        return [
            'id' => $d->id,
            'anio' => (int) $d->anio,
            'mes' => $mes,
            'modulo' => $d->modulo,
            'periodo_label' => ucfirst($mesNombre).' '.((int) $d->anio),
            'mes_gestion' => sprintf('%04d-%02d', $d->anio, $d->mes),
            'nombre_original' => $d->nombre_original,
            'formato' => $d->formato,
            'tamano_bytes' => $d->tamano_bytes,
            'fecha_subida' => $d->fecha_subida?->toIso8601String(),
            'monto_total_ganado' => $d->monto_total_ganado,
            'monto_deposito_cns' => $d->monto_deposito_cns,
            'monto_aportes_gestoras' => $d->monto_aportes_gestoras,
            'monto_aporte_solidario_gestora' => $d->monto_aporte_solidario_gestora,
            'monto_planilla_mensual_mdt' => $d->monto_planilla_mensual_mdt,
            'monto_seprec_registro_poder_consultora' => $d->monto_seprec_registro_poder_consultora,
        ];
    }
}
