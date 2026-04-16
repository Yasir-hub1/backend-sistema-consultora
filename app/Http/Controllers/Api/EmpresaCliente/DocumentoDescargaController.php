<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use App\Models\Documento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentoDescargaController extends ApiController
{
    /**
     * Devuelve metadatos; el front puede llamar a stream con el mismo id.
     */
    public function url(Request $request, int $documentoId): JsonResponse
    {
        $emp = $request->user()->empresaClienteComoUsuario;
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $doc = $this->resolverDocumento($emp->id, $documentoId);
        if (! $doc) {
            return $this->fail('Documento no encontrado', 404);
        }

        return $this->ok([
            'url' => url("/api/empresa-cliente/documentos/{$documentoId}/stream"),
            'nombre_original' => $doc->nombre_original,
        ]);
    }

    public function stream(Request $request, int $documentoId): StreamedResponse|JsonResponse
    {
        $emp = $request->user()->empresaClienteComoUsuario;
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $doc = $this->resolverDocumento($emp->id, $documentoId);
        if (! $doc || $doc->eliminado) {
            return $this->fail('Documento no encontrado', 404);
        }

        if (! Storage::disk('local')->exists($doc->ruta_archivo)) {
            return $this->fail('Archivo no disponible', 404);
        }

        return Storage::disk('local')->response($doc->ruta_archivo, $doc->nombre_original);
    }

    private function resolverDocumento(int $empresaId, int $documentoId): ?Documento
    {
        return Documento::query()
            ->whereKey($documentoId)
            ->whereHas('personal', fn ($q) => $q->where('empresa_id', $empresaId))
            ->first();
    }
}
