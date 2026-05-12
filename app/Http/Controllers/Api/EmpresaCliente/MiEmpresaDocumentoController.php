<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaClienteDocumentoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MiEmpresaDocumentoController extends ApiController
{
    private const TIPOS = [
        'nit' => 'NIT',
        'roe' => 'ROE',
        'matricula_comercio' => 'MATRICULA DE COMERCIO',
        'licencia_funcionamiento' => 'LICENCIA DE FUNCIONAMIENTO',
        'certificado_patronal_caja' => 'CERTIFICADO PARTERNAL DE LA CAJA',
        'formulario_inscripcion_gestora' => 'FORMULARIO DE INSCRIPCION DE GESTORA',
        'certificacion_nit' => 'CERTIFICACION DE NIT',
    ];

    public function index(Request $request): JsonResponse
    {
        $empresa = $request->user()?->empresaClienteComoUsuario;
        if (! $empresa) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $documentos = EmpresaClienteDocumentoEmpresa::query()
            ->where('empresa_cliente_id', $empresa->id)
            ->get()
            ->keyBy('tipo_documento');

        $items = [];
        foreach (self::TIPOS as $key => $label) {
            $doc = $documentos->get($key);
            $items[] = [
                'tipo_documento' => $key,
                'titulo' => $label,
                'subido' => (bool) $doc,
                'id' => $doc?->id,
                'nombre_original' => $doc?->nombre_original,
                'formato' => $doc?->formato,
                'tamano_bytes' => $doc?->tamano_bytes,
                'fecha_subida' => optional($doc?->fecha_subida)->toIso8601String(),
            ];
        }

        return $this->ok(['items' => $items]);
    }

    public function vistaPrevia(Request $request, string $tipo): StreamedResponse|JsonResponse
    {
        $doc = $this->resolverDocumento($request, $tipo);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        return Storage::disk('local')->response($doc->ruta_archivo, $doc->nombre_original, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$doc->nombre_original.'"',
        ]);
    }

    public function descargar(Request $request, string $tipo): StreamedResponse|JsonResponse
    {
        $doc = $this->resolverDocumento($request, $tipo);
        if ($doc instanceof JsonResponse) {
            return $doc;
        }

        return Storage::disk('local')->download($doc->ruta_archivo, $doc->nombre_original, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function resolverDocumento(Request $request, string $tipo): EmpresaClienteDocumentoEmpresa|JsonResponse
    {
        if (! array_key_exists($tipo, self::TIPOS)) {
            return $this->fail('Tipo de documento no permitido.', 422);
        }

        $empresa = $request->user()?->empresaClienteComoUsuario;
        if (! $empresa) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $doc = EmpresaClienteDocumentoEmpresa::query()
            ->where('empresa_cliente_id', $empresa->id)
            ->where('tipo_documento', $tipo)
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

