<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use App\Models\DeclaracionAguinaldo;
use App\Models\EmpresaCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeclaracionAguinaldoController extends ApiController
{
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

        $items = DeclaracionAguinaldo::query()
            ->where('empresa_cliente_id', $emp->id)
            ->orderByDesc('anio')
            ->limit(20)
            ->get()
            ->map(fn (DeclaracionAguinaldo $d) => $this->serializar($d));

        return $this->ok(['items' => $items]);
    }

    public function vistaPrevia(Request $request, int $id): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $emp = $this->empresa($request);
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $dec = DeclaracionAguinaldo::query()
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

    public function descargar(Request $request, int $id): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $emp = $this->empresa($request);
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $dec = DeclaracionAguinaldo::query()
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

    private function serializar(DeclaracionAguinaldo $d): array
    {
        return [
            'id' => $d->id,
            'anio' => (int) $d->anio,
            'periodo_label' => 'Gestión '.$d->anio,
            'nombre_original' => $d->nombre_original,
            'formato' => $d->formato,
            'tamano_bytes' => $d->tamano_bytes,
            'fecha_subida' => $d->fecha_subida?->toIso8601String(),
        ];
    }
}

