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

        $mesGestion = $request->string('mes_gestion')->toString();
        if ($mesGestion !== '' && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesGestion)) {
            return $this->fail('El mes de gestión no es válido.', 422);
        }

        $items = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->when(
                in_array($request->query('modulo'), ['afp', 'caja', 'ministerio'], true),
                fn ($q) => $q->where('modulo', $request->query('modulo'))
            )
            ->when($mesGestion !== '', function ($q) use ($mesGestion) {
                [$anio, $mes] = array_map('intval', explode('-', $mesGestion));
                $q->where('anio', $anio)->where('mes', $mes);
            })
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->orderBy('modulo')
            ->when($mesGestion === '', fn ($q) => $q->limit(36))
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

        $request->validate([
            'modulo' => ['required', 'in:afp,caja,ministerio'],
            'mes_gestion' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'archivo' => ['nullable', 'file', 'max:15360', 'mimes:pdf'],
            'monto_total_ganado' => ['nullable', 'numeric', 'min:0'],
            'monto_deposito_cns' => ['nullable', 'numeric', 'min:0'],
            'monto_aportes_gestoras' => ['nullable', 'numeric', 'min:0'],
            'monto_aporte_solidario_gestora' => ['nullable', 'numeric', 'min:0'],
            'monto_planilla_mensual_mdt' => ['nullable', 'numeric', 'min:0'],
            'monto_seprec_registro_poder_consultora' => ['nullable', 'numeric', 'min:0'],
        ]);

        $modulo = $request->string('modulo')->toString();
        if (! ColaboradorAutorizacionService::puedeCargarDeclaracionMensual($u, $empresaClienteId, $modulo)) {
            return $this->fail('No tienes permiso para cargar declaraciones de este módulo.', 403);
        }

        $parts = explode('-', $request->string('mes_gestion')->toString());
        $anio = (int) $parts[0];
        $mes = (int) $parts[1];

        $file = $request->file('archivo');
        $montos = $this->montosNormalizadosParaModulo($modulo, $request);
        $tieneMontos = collect($montos)->contains(static fn ($valor): bool => $valor !== null);

        $existente = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $empresaClienteId)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->where('modulo', $modulo)
            ->first();

        if (! $file && ! $tieneMontos && ! $existente) {
            return $this->fail('Indica al menos un monto o adjunta el PDF.', 422);
        }

        $colabId = $u->colaborador?->id;
        $datos = [
            'monto_total_ganado' => $montos['monto_total_ganado'],
            'monto_deposito_cns' => $montos['monto_deposito_cns'],
            'monto_aportes_gestoras' => $montos['monto_aportes_gestoras'],
            'monto_aporte_solidario_gestora' => $montos['monto_aporte_solidario_gestora'],
            'monto_planilla_mensual_mdt' => $montos['monto_planilla_mensual_mdt'],
            'monto_seprec_registro_poder_consultora' => $montos['monto_seprec_registro_poder_consultora'],
            'subido_por' => $colabId,
            'fecha_subida' => now(),
        ];

        if ($file) {
            if ($existente?->archivoDisponible()) {
                Storage::disk('local')->delete($existente->ruta_archivo);
            }

            $consultoraId = $emp->consultora_id;
            $dir = "docs/consultora_{$consultoraId}/empresa_{$empresaClienteId}/declaraciones_mensuales";
            $stored = $file->store($dir, 'local');
            $datos['nombre_archivo'] = basename($stored);
            $datos['nombre_original'] = $file->getClientOriginalName();
            $datos['ruta_archivo'] = $stored;
            $datos['formato'] = strtolower($file->getClientOriginalExtension());
            $datos['tamano_bytes'] = $file->getSize();
        }

        $row = DeclaracionMensual::query()->updateOrCreate(
            [
                'empresa_cliente_id' => $empresaClienteId,
                'anio' => $anio,
                'mes' => $mes,
                'modulo' => $modulo,
            ],
            $datos,
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
            $detalle = $file
                ? "Se cargó la declaración {$modLabel} correspondiente a {$periodo}."
                : "Se registraron montos de la declaración {$modLabel} correspondiente a {$periodo}.";

            Alerta::create([
                'consultora_id' => $empresa->consultora_id,
                'empresa_id' => $empresa->id,
                'personal_id' => null,
                'colaborador_asignado' => null,
                'modulo' => 'declaracion_mensual',
                'nivel' => 'normal',
                'titulo' => 'Nueva declaración mensual',
                'descripcion' => $detalle,
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
        if (! $dec->archivoDisponible()) {
            return $this->fail('Esta declaración no tiene PDF.', 404);
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
        if (! $dec->archivoDisponible()) {
            return $this->fail('Esta declaración no tiene PDF.', 404);
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

    /**
     * Solo persisten montos del módulo elegido; el resto queda en null para reportes por período limpios.
     *
     * @return array<string, float|string|null>
     */
    private function montosNormalizadosParaModulo(string $modulo, Request $request): array
    {
        $todas = [
            'monto_total_ganado',
            'monto_deposito_cns',
            'monto_aportes_gestoras',
            'monto_aporte_solidario_gestora',
            'monto_planilla_mensual_mdt',
            'monto_seprec_registro_poder_consultora',
        ];
        $permitidas = match ($modulo) {
            'afp' => ['monto_aportes_gestoras', 'monto_aporte_solidario_gestora'],
            'caja' => ['monto_deposito_cns'],
            'ministerio' => [
                'monto_total_ganado',
                'monto_planilla_mensual_mdt',
                'monto_seprec_registro_poder_consultora',
            ],
            default => [],
        };
        $out = array_fill_keys($todas, null);
        foreach ($permitidas as $clave) {
            $v = $request->input($clave);
            $out[$clave] = ($v === '' || $v === null) ? null : $v;
        }

        return $out;
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
            'tiene_archivo' => $d->archivoDisponible(),
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
