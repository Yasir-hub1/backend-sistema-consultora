<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alerta;
use App\Models\DeclaracionMensual;
use App\Models\EmpresaCliente;
use App\Services\ColaboradorAutorizacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeclaracionMensualController extends ApiController
{
    private const MESES = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    public function index(Request $request, int $empresaClienteId): JsonResponse
    {
        $emp = ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId);
        if (! $emp) {
            return $this->fail('Sin acceso', 403);
        }

        $items = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->when(
                in_array($request->query('modulo'), ['afp', 'caja', 'ministerio'], true),
                fn ($q) => $q->where('modulo', $request->query('modulo'))
            )
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->orderBy('modulo')
            ->limit(36)
            ->get()
            ->map(fn (DeclaracionMensual $d) => $this->serializar($d));

        return $this->ok(['items' => $items]);
    }

    public function store(Request $request, int $empresaClienteId): JsonResponse
    {
        $emp = ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId);
        if (! $emp) {
            return $this->fail('Sin acceso', 403);
        }

        $u = $request->user();
        $puedeSubir = ColaboradorAutorizacionService::puedeEditarPersonal($u, $empresaClienteId)
            || ColaboradorAutorizacionService::puedeRegistrarPersonal($u, $empresaClienteId);
        if (! $puedeSubir) {
            return $this->fail('No tienes permiso para cargar declaraciones.', 403);
        }

        $request->validate([
            'modulo' => ['required', 'in:afp,caja,ministerio'],
            'mes_gestion' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'archivo' => ['required', 'file', 'max:15360', 'mimes:pdf'],
            'monto_total_ganado' => ['nullable', 'numeric', 'min:0'],
            'monto_deposito_cns' => ['nullable', 'numeric', 'min:0'],
            'monto_aportes_gestoras' => ['nullable', 'numeric', 'min:0'],
            'monto_aporte_solidario_gestora' => ['nullable', 'numeric', 'min:0'],
            'monto_planilla_mensual_mdt' => ['nullable', 'numeric', 'min:0'],
            'monto_seprec_registro_poder_consultora' => ['nullable', 'numeric', 'min:0'],
        ]);

        $parts = explode('-', $request->string('mes_gestion')->toString());
        $anio = (int) $parts[0];
        $mes = (int) $parts[1];
        $modulo = $request->string('modulo')->toString();

        $file = $request->file('archivo');
        $ext = strtolower($file->getClientOriginalExtension());
        $colabId = $u->colaborador?->id;

        $consultoraId = $emp->consultora_id;
        $dir = "docs/consultora_{$consultoraId}/empresa_{$empresaClienteId}/declaraciones_mensuales";

        $existente = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->where('modulo', $modulo)
            ->first();

        if ($existente && Storage::disk('local')->exists($existente->ruta_archivo)) {
            Storage::disk('local')->delete($existente->ruta_archivo);
        }

        $stored = $file->store($dir, 'local');

        $row = DeclaracionMensual::query()->updateOrCreate(
            [
                'empresa_cliente_id' => $empresaClienteId,
                'anio' => $anio,
                'mes' => $mes,
                'modulo' => $modulo,
            ],
            [
                'monto_total_ganado' => $request->input('monto_total_ganado'),
                'monto_deposito_cns' => $request->input('monto_deposito_cns'),
                'monto_aportes_gestoras' => $request->input('monto_aportes_gestoras'),
                'monto_aporte_solidario_gestora' => $request->input('monto_aporte_solidario_gestora'),
                'monto_planilla_mensual_mdt' => $request->input('monto_planilla_mensual_mdt'),
                'monto_seprec_registro_poder_consultora' => $request->input('monto_seprec_registro_poder_consultora'),
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
            $mesNombre = self::MESES[$mes] ?? 'mes';
            $modLabels = [
                'afp' => 'AFP',
                'caja' => 'CAJA',
                'ministerio' => 'Ministerio de Trabajo',
            ];
            $modLabel = $modLabels[$modulo] ?? strtoupper($modulo);
            $periodo = ucfirst($mesNombre).' '.$anio;

            Alerta::create([
                'consultora_id' => $empresa->consultora_id,
                'empresa_id' => $empresa->id,
                'personal_id' => null,
                'colaborador_asignado' => null,
                'modulo' => 'declaracion_mensual',
                'nivel' => 'normal',
                'titulo' => 'Nueva declaración mensual',
                'descripcion' => "Se cargó la declaración {$modLabel} correspondiente a {$periodo}.",
                'generada_auto' => true,
                'contexto' => [
                    'paths' => [
                        'empresa_cliente' => '/empresa-cliente/declaraciones-mensuales',
                        'consultora' => '/consultora/reportes',
                    ],
                ],
            ]);
        }

        return $this->ok($this->serializar($row->fresh()), 'Declaración guardada', 201);
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

        $dec = DeclaracionMensual::query()
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

        $dec = DeclaracionMensual::query()
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
