<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaClienteOtroDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OtrosDocumentosController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $emp = $request->user()->empresaClienteComoUsuario;
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $items = EmpresaClienteOtroDocumento::query()
            ->where('empresa_cliente_id', $emp->id)
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

    public function vistaPrevia(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $doc = $this->resolverDocumento($request, $id);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        if (! Storage::disk('local')->exists($doc->ruta_archivo)) {
            return $this->fail('El archivo no existe en almacenamiento.', 404);
        }

        return Storage::disk('local')->response($doc->ruta_archivo, $doc->nombre_original, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->safeName($doc->nombre_original).'"',
        ]);
    }

    public function descargar(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $doc = $this->resolverDocumento($request, $id);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        if (! Storage::disk('local')->exists($doc->ruta_archivo)) {
            return $this->fail('El archivo no existe en almacenamiento.', 404);
        }

        return Storage::disk('local')->download($doc->ruta_archivo, $doc->nombre_original, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function resolverDocumento(Request $request, int $id): EmpresaClienteOtroDocumento|JsonResponse
    {
        $emp = $request->user()->empresaClienteComoUsuario;
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $doc = EmpresaClienteOtroDocumento::query()
            ->where('empresa_cliente_id', $emp->id)
            ->whereKey($id)
            ->first();

        if (! $doc) {
            return $this->fail('Documento no encontrado.', 404);
        }

        return $doc;
    }

    private function safeName(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', $name);
    }
}
