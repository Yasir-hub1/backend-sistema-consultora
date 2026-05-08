<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaClienteOtroDocumento;
use App\Services\ColaboradorAutorizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmpresaClienteOtroDocumentoController extends ApiController
{
    public function index(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $items = EmpresaClienteOtroDocumento::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->orderByDesc('fecha_subida')
            ->orderByDesc('id')
            ->get()
            ->map(static function (EmpresaClienteOtroDocumento $d) {
                return [
                    'id' => $d->id,
                    'nombre_original' => $d->nombre_original,
                    'descripcion' => $d->descripcion,
                    'formato' => $d->formato,
                    'tamano_bytes' => $d->tamano_bytes,
                    'fecha_subida' => optional($d->fecha_subida)->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return $this->ok(['items' => $items]);
    }

    public function store(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeGestionarOtrosDocumentosEmpresa($request->user(), $empresaClienteId)) {
            return $this->fail('No autorizado para subir documentos en esta sección.', 403);
        }

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('archivo');
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()) ?: 'documento.pdf';
        $filename = sprintf('otros_%s_%s', now()->format('YmdHis'), $safeName);
        $path = sprintf('empresas/%d/otros-documentos-personal/%s', $empresaClienteId, $filename);
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $doc = EmpresaClienteOtroDocumento::query()->create([
            'empresa_cliente_id' => $empresaClienteId,
            'descripcion' => isset($data['descripcion']) ? trim((string) $data['descripcion']) ?: null : null,
            'nombre_original' => $file->getClientOriginalName(),
            'ruta_archivo' => $path,
            'formato' => 'pdf',
            'tamano_bytes' => $file->getSize(),
            'subido_por' => $request->user()->colaborador?->id,
            'fecha_subida' => now(),
        ]);

        return $this->ok([
            'id' => $doc->id,
            'nombre_original' => $doc->nombre_original,
            'descripcion' => $doc->descripcion,
            'formato' => $doc->formato,
            'tamano_bytes' => $doc->tamano_bytes,
            'fecha_subida' => optional($doc->fecha_subida)->toIso8601String(),
        ], 'Documento guardado.', 201);
    }

    public function vistaPrevia(Request $request, int $empresaClienteId, int $id): StreamedResponse|JsonResponse
    {
        $doc = $this->resolverDocumento($request, $empresaClienteId, $id);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        return Storage::disk('local')->response($doc->ruta_archivo, $doc->nombre_original, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$doc->nombre_original.'"',
        ]);
    }

    public function descargar(Request $request, int $empresaClienteId, int $id): StreamedResponse|JsonResponse
    {
        $doc = $this->resolverDocumento($request, $empresaClienteId, $id);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        return Storage::disk('local')->download($doc->ruta_archivo, $doc->nombre_original, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function resolverDocumento(Request $request, int $empresaClienteId, int $id): EmpresaClienteOtroDocumento|JsonResponse
    {
        if (! ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $doc = EmpresaClienteOtroDocumento::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->whereKey($id)
            ->first();

        if (! $doc) {
            return $this->fail('Documento no encontrado.', 404);
        }

        if (! Storage::disk('local')->exists($doc->ruta_archivo)) {
            return $this->fail('El archivo no existe en almacenamiento.', 404);
        }

        return $doc;
    }
}
