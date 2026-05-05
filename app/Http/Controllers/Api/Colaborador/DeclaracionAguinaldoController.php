<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alerta;
use App\Models\DeclaracionAguinaldo;
use App\Models\EmpresaCliente;
use App\Services\ColaboradorAutorizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeclaracionAguinaldoController extends ApiController
{
    public function index(Request $request, int $empresaClienteId): JsonResponse
    {
        $emp = ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId);
        if (! $emp) {
            return $this->fail('Sin acceso', 403);
        }

        $items = DeclaracionAguinaldo::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->orderByDesc('anio')
            ->limit(50)
            ->get()
            ->map(fn (DeclaracionAguinaldo $d) => $this->serializar($d));

        return $this->ok(['items' => $items]);
    }

    public function store(Request $request, int $empresaClienteId): JsonResponse
    {
        $emp = ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId);
        if (! $emp) {
            return $this->fail('Sin acceso', 403);
        }

        $u = $request->user();
        if (! ColaboradorAutorizacionService::puedeCargarDeclaracionAguinaldo($u, $empresaClienteId)) {
            return $this->fail('No tienes permiso para cargar declaraciones de aguinaldo.', 403);
        }

        $request->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'archivo' => ['required', 'file', 'max:15360', 'mimes:pdf'],
        ]);

        $anio = (int) $request->input('anio');
        $file = $request->file('archivo');
        $ext = strtolower($file->getClientOriginalExtension());
        $colabId = $u->colaborador?->id;

        $consultoraId = $emp->consultora_id;
        $dir = "docs/consultora_{$consultoraId}/empresa_{$empresaClienteId}/declaraciones_aguinaldo";

        $existente = DeclaracionAguinaldo::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->where('anio', $anio)
            ->first();

        if ($existente && Storage::disk('local')->exists($existente->ruta_archivo)) {
            Storage::disk('local')->delete($existente->ruta_archivo);
        }

        $stored = $file->store($dir, 'local');

        $row = DeclaracionAguinaldo::query()->updateOrCreate(
            [
                'empresa_cliente_id' => $empresaClienteId,
                'anio' => $anio,
            ],
            [
                'nombre_archivo' => basename($stored),
                'nombre_original' => $file->getClientOriginalName(),
                'ruta_archivo' => $stored,
                'formato' => $ext,
                'tamano_bytes' => $file->getSize(),
                'subido_por' => $colabId,
                'fecha_subida' => now(),
            ]
        );

        $empresa = EmpresaCliente::query()->find($empresaClienteId);
        if ($empresa) {
            Alerta::create([
                'consultora_id' => $empresa->consultora_id,
                'empresa_id' => $empresa->id,
                'personal_id' => null,
                'colaborador_asignado' => null,
                'modulo' => 'declaracion_aguinaldo',
                'nivel' => 'normal',
                'titulo' => 'Declaración anual de aguinaldo',
                'descripcion' => "Se cargó la declaración de aguinaldo correspondiente al año {$anio}.",
                'generada_auto' => true,
                'contexto' => [
                    'paths' => [
                        'consultora' => '/consultora/mis-empresas',
                        'empresa_cliente' => '/empresa-cliente/dashboard',
                    ],
                ],
            ]);
        }

        return $this->ok($this->serializar($row->fresh()), 'Declaración de aguinaldo guardada', 201);
    }

    public function vistaPrevia(Request $request, int $empresaClienteId, int $id): BinaryFileResponse|JsonResponse
    {
        return $this->entregarArchivo($request, $empresaClienteId, $id, inline: true);
    }

    public function descargar(Request $request, int $empresaClienteId, int $id): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        $emp = ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId);
        if (! $emp) {
            return $this->fail('Sin acceso', 403);
        }

        $dec = DeclaracionAguinaldo::query()
            ->where('empresa_cliente_id', $empresaClienteId)
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

    private function entregarArchivo(Request $request, int $empresaClienteId, int $id, bool $inline): BinaryFileResponse|JsonResponse
    {
        $emp = ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId);
        if (! $emp) {
            return $this->fail('Sin acceso', 403);
        }

        $dec = DeclaracionAguinaldo::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->whereKey($id)
            ->first();
        if (! $dec) {
            return $this->fail('No encontrada', 404);
        }
        $abs = Storage::disk('local')->path($dec->ruta_archivo);
        if (! is_readable($abs)) {
            return $this->fail('Archivo no disponible', 404);
        }

        $disposition = $inline ? 'inline' : 'attachment';

        return response()->file($abs, [
            'Content-Disposition' => $disposition.'; filename="'.$this->nombreArchivoSeguro($dec->nombre_original).'"',
        ]);
    }

    private function nombreArchivoSeguro(string $name): string
    {
        return str_replace(['"', "\r", "\n"], '', $name);
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
