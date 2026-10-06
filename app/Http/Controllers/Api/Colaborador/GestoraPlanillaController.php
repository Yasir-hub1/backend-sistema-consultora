<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\DeclaracionMensual;
use App\Models\Personal;
use App\Services\CartaAportesPdfService;
use App\Services\ColaboradorAutorizacionService;
use App\Services\GestoraAporteCalculator;
use App\Services\GestoraPlanillaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GestoraPlanillaController extends ApiController
{
    public function __construct(
        private readonly GestoraPlanillaService $gestoraPlanillaService,
        private readonly CartaAportesPdfService $cartaAportesPdf,
        private readonly GestoraAporteCalculator $calculator,
    ) {}

    public function index(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $periodo = $this->periodo($request);

        $pagina = $this->gestoraPlanillaService->listar(
            $empresaClienteId,
            $periodo['anio'],
            $periodo['mes'],
            $request->query('search') !== null ? (string) $request->query('search') : null,
            (int) $request->query('per_page', 15),
        );

        return $this->ok([
            'data' => $pagina->items(),
            'current_page' => $pagina->currentPage(),
            'last_page' => $pagina->lastPage(),
            'total' => $pagina->total(),
            'resumen' => $this->gestoraPlanillaService->resumenPeriodo($empresaClienteId, $periodo['anio'], $periodo['mes']),
            'periodo' => [
                'anio' => $periodo['anio'],
                'mes' => $periodo['mes'],
                'etiqueta' => $this->gestoraPlanillaService->etiquetaPeriodo($periodo['anio'], $periodo['mes']),
            ],
        ]);
    }

    public function generar(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $periodo = $this->periodo($request);

        return $this->ok(
            $this->gestoraPlanillaService->generar($empresaClienteId, $periodo['anio'], $periodo['mes']),
            'Planilla de aportes generada.',
        );
    }

    public function pdf(Request $request, int $empresaClienteId): Response|JsonResponse
    {
        $empresa = ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId);
        if (! $empresa) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $periodo = $this->periodo($request);
        $planilla = $this->gestoraPlanillaService->generar($empresaClienteId, $periodo['anio'], $periodo['mes']);
        $consolidado = $planilla['consolidado'];
        if (bccomp($consolidado['total_ganado'], '0.00', 2) !== 1) {
            return $this->fail('No hay total ganado para armar la carta de este periodo.', 422);
        }

        $empresa->loadMissing('consultora');
        if (! $empresa->consultora) {
            return $this->fail('La empresa no tiene consultora asociada.', 422);
        }

        $declaraciones = DeclaracionMensual::query()
            ->where('empresa_cliente_id', $empresa->id)
            ->where('anio', $periodo['anio'])
            ->where('mes', $periodo['mes'])
            ->get(['monto_planilla_mensual_mdt', 'monto_seprec_registro_poder_consultora']);

        $planillaMdt = $this->calculator->sumar(
            ...$declaraciones->map(fn (DeclaracionMensual $row): string => $this->calculator->normalizarMonto($row->monto_planilla_mensual_mdt))->all(),
        );
        $seprec = $this->calculator->sumar(
            ...$declaraciones->map(fn (DeclaracionMensual $row): string => $this->calculator->normalizarMonto($row->monto_seprec_registro_poder_consultora))->all(),
        );
        $totalAportes = $this->calculator->sumar(
            $consolidado['cns'],
            $consolidado['aporte_afp'],
            $consolidado['ans'],
            $planillaMdt,
            $seprec,
        );

        return $this->cartaAportesPdf->responder($empresa, $empresa->consultora, [
            'total_ganado' => (float) $consolidado['total_ganado'],
            'deposito_cns' => (float) $consolidado['cns'],
            'aportes_gestora' => (float) $consolidado['aporte_afp'],
            'aporte_solidario' => (float) $consolidado['ans'],
            'planilla_mdt' => (float) $planillaMdt,
            'seprec' => (float) $seprec,
            'total_aportes' => (float) $totalAportes,
        ], $periodo['anio'], $periodo['mes'], true);
    }

    public function update(Request $request, int $empresaClienteId, int $personalId): JsonResponse
    {
        if (! ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeEditarPersonal($request->user(), $empresaClienteId)) {
            return $this->fail('No autorizado para editar datos de personal.', 403);
        }

        $personal = Personal::query()
            ->where('empresa_id', $empresaClienteId)
            ->find($personalId);

        if (! $personal) {
            return $this->fail('No encontrado', 404);
        }

        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'numero_cua' => ['nullable', 'string', 'max:40'],
            'dias_trabajados' => ['required', 'integer', 'min:0', 'max:31'],
            'total_ganado' => ['required', 'regex:/^\d{1,8}([.,]\d{1,2})?$/'],
            'habilitado' => ['required', 'boolean'],
        ], [
            'total_ganado.regex' => 'El total ganado debe ser un monto válido, por ejemplo 8500.00.',
        ]);

        $resultado = $this->gestoraPlanillaService->actualizar($personal, (int) $datos['anio'], (int) $datos['mes'], $datos);
        if (isset($resultado['error'])) {
            return $this->fail($resultado['error'], 422);
        }

        return $this->ok($resultado['fila'], 'Datos de gestora actualizados.');
    }

    public function actualizarLote(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeEditarPersonal($request->user(), $empresaClienteId)) {
            return $this->fail('No autorizado para editar datos de personal.', 403);
        }

        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'filas' => ['required', 'array', 'min:1', 'max:100'],
            'filas.*.personal_id' => ['required', 'integer', 'distinct'],
            'filas.*.dias_trabajados' => ['required', 'integer', 'min:0', 'max:31'],
            'filas.*.total_ganado' => ['required', 'regex:/^\d{1,8}([.,]\d{1,2})?$/'],
        ], [
            'filas.*.dias_trabajados.*' => 'Los días trabajados deben ser un entero entre 0 y 31.',
            'filas.*.total_ganado.*' => 'El total ganado debe ser un monto válido, por ejemplo 8500.00.',
        ]);

        $resultado = $this->gestoraPlanillaService->actualizarLote(
            $empresaClienteId,
            (int) $datos['anio'],
            (int) $datos['mes'],
            $datos['filas'],
        );
        if (isset($resultado['error'])) {
            return $this->fail($resultado['error'], 422);
        }

        return $this->ok($resultado, 'Días y total ganado guardados.');
    }

    /**
     * @return array{anio: int, mes: int}
     */
    private function periodo(Request $request): array
    {
        $datos = $request->validate([
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return [
            'anio' => (int) $datos['anio'],
            'mes' => (int) $datos['mes'],
        ];
    }
}
