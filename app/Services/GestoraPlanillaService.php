<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Personal;
use App\Models\PersonalGestoraPeriodo;
use App\Support\NumeroCua;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class GestoraPlanillaService
{
    public const DIAS_POR_DEFECTO = 30;

    /** @var array<int, string> */
    private const MESES = [
        1 => 'Enero',
        2 => 'Febrero',
        3 => 'Marzo',
        4 => 'Abril',
        5 => 'Mayo',
        6 => 'Junio',
        7 => 'Julio',
        8 => 'Agosto',
        9 => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre',
    ];

    public function __construct(
        private readonly GestoraAporteCalculator $calculator,
    ) {}

    public function etiquetaPeriodo(int $anio, int $mes): string
    {
        return (self::MESES[$mes] ?? 'Mes').' '.$anio;
    }

    public function listar(int $empresaId, int $anio, int $mes, ?string $busqueda, int $porPagina): LengthAwarePaginator
    {
        $pagina = $this->consultaPeriodo($empresaId, $anio, $mes, $busqueda)
            ->paginate(max(1, min($porPagina, 100)));

        $pagina->setCollection(
            $pagina->getCollection()->map(fn (Personal $personal): array => $this->filaListado($personal))
        );

        return $pagina;
    }

    /**
     * Conteos del periodo sobre todo el personal vigente, no solo la página listada.
     *
     * @return array{vigentes: int, habilitados: int, con_total: int, deshabilitados: int}
     */
    public function resumenPeriodo(int $empresaId, int $anio, int $mes): array
    {
        $filas = $this->consultaPeriodo($empresaId, $anio, $mes, null)
            ->get()
            ->map(fn (Personal $personal): array => $this->filaListado($personal));

        $habilitadas = $filas->where('habilitado', true);

        return [
            'vigentes' => $filas->count(),
            'habilitados' => $habilitadas->count(),
            'con_total' => $habilitadas
                ->filter(fn (array $fila): bool => bccomp($fila['total_ganado'], '0.00', 2) === 1)
                ->count(),
            'deshabilitados' => $filas->count() - $habilitadas->count(),
        ];
    }

    /**
     * @param  array{numero_cua: mixed, dias_trabajados: int, total_ganado: mixed, habilitado: bool}  $datos
     * @return array{fila: array<string, mixed>}|array{error: string}
     */
    public function actualizar(Personal $personal, int $anio, int $mes, array $datos): array
    {
        $cua = NumeroCua::resolver($datos['numero_cua'] ?? null);
        if ($cua['error'] !== null) {
            return ['error' => $cua['error']];
        }

        if ($cua['valor'] !== null && $this->cuaOcupado((int) $personal->empresa_id, $cua['valor'], (int) $personal->id)) {
            return ['error' => 'El CUA/RUA ya está registrado en esta empresa.'];
        }

        $total = $this->calculator->normalizarMonto($datos['total_ganado'] ?? '0');
        if (bccomp($total, '0.00', 2) === -1) {
            return ['error' => 'El total ganado no puede ser negativo.'];
        }

        if (! filter_var($datos['habilitado'], FILTER_VALIDATE_BOOLEAN) && $this->hayDeshabilitados([(int) $personal->id], $anio, $mes)) {
            return ['error' => 'Esta persona está deshabilitada en este mes. Habilitala primero para cambiar sus datos.'];
        }

        DB::transaction(function () use ($personal, $anio, $mes, $cua, $datos, $total): void {
            $personal->numero_cua = $cua['valor'];
            $personal->save();

            PersonalGestoraPeriodo::query()->updateOrCreate(
                [
                    'personal_id' => $personal->id,
                    'anio' => $anio,
                    'mes' => $mes,
                ],
                [
                    'dias_trabajados' => $datos['dias_trabajados'],
                    'total_ganado' => $total,
                    'habilitado' => $datos['habilitado'],
                ],
            );
        });

        $personal->load(['gestoraPeriodos' => function ($query) use ($anio, $mes): void {
            $query->where('anio', $anio)->where('mes', $mes);
        }]);

        return ['fila' => $this->filaListado($personal)];
    }

    /**
     * Guarda días y total ganado de varias personas del periodo. Conserva el estado habilitado de cada una.
     *
     * @param  list<array{personal_id: int, dias_trabajados: int, total_ganado: mixed}>  $filas
     * @return array{guardados: int}|array{error: string}
     */
    public function actualizarLote(int $empresaId, int $anio, int $mes, array $filas): array
    {
        $ids = array_map(static fn (array $fila): int => (int) $fila['personal_id'], $filas);

        $vigentes = $this->consultaPeriodo($empresaId, $anio, $mes, null)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($vigentes) !== count(array_unique($ids))) {
            return ['error' => 'Alguna persona ya no está vigente en este mes. Recargá la lista e intentá de nuevo.'];
        }

        if ($this->hayDeshabilitados($ids, $anio, $mes)) {
            return ['error' => 'Hay personas deshabilitadas en este mes. Habilitalas primero para cargar sus días y total ganado.'];
        }

        DB::transaction(function () use ($anio, $mes, $filas): void {
            foreach ($filas as $fila) {
                $periodo = PersonalGestoraPeriodo::query()->firstOrNew([
                    'personal_id' => (int) $fila['personal_id'],
                    'anio' => $anio,
                    'mes' => $mes,
                ]);
                $periodo->dias_trabajados = (int) $fila['dias_trabajados'];
                $periodo->total_ganado = $this->calculator->normalizarMonto($fila['total_ganado']);
                $periodo->habilitado ??= true;
                $periodo->save();
            }
        });

        return ['guardados' => count($filas)];
    }

    /**
     * Días y total del alta. Vacío en ambos significa no crear periodo.
     *
     * @return array{omitir: true}|array{omitir: false, dias: int, total: string, error?: never}|array{error: string}
     */
    public function normalizarPeriodoInicial(mixed $dias, mixed $total): array
    {
        $diasTexto = $this->textoCelda($dias);
        $totalTexto = $this->textoCelda($total);
        if ($diasTexto === '' && $totalTexto === '') {
            return ['omitir' => true];
        }

        if ($diasTexto === '') {
            $diasNumero = self::DIAS_POR_DEFECTO;
        } elseif (! preg_match('/^\d+$/', $diasTexto)) {
            return ['error' => 'Los días trabajados deben ser un entero entre 0 y 31.'];
        } else {
            $diasNumero = (int) $diasTexto;
            if ($diasNumero > 31) {
                return ['error' => 'Los días trabajados deben ser un entero entre 0 y 31.'];
            }
        }

        if ($totalTexto === '') {
            $monto = '0.00';
        } else {
            $normalizado = str_replace(',', '.', $totalTexto);
            if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalizado)) {
                return ['error' => 'El total ganado debe ser un monto válido, por ejemplo 8500.00.'];
            }
            $monto = $this->calculator->normalizarMonto($normalizado);
        }

        return [
            'omitir' => false,
            'dias' => $diasNumero,
            'total' => $monto,
        ];
    }

    public function guardarPeriodoInicial(Personal $personal, int $dias, string $total): void
    {
        $fecha = Carbon::parse($personal->fecha_ingreso);

        PersonalGestoraPeriodo::query()->updateOrCreate(
            [
                'personal_id' => $personal->id,
                'anio' => (int) $fecha->year,
                'mes' => (int) $fecha->month,
            ],
            [
                'dias_trabajados' => $dias,
                'total_ganado' => $total,
                'habilitado' => true,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function generar(int $empresaId, int $anio, int $mes): array
    {
        $personal = $this->consultaPeriodo($empresaId, $anio, $mes, null)
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->get();

        $filas = [];
        $deshabilitados = 0;
        $sinTotal = 0;

        foreach ($personal as $empleado) {
            $vista = $this->filaListado($empleado);
            if ($vista['habilitado'] !== true) {
                $deshabilitados++;

                continue;
            }

            if (bccomp($vista['total_ganado'], '0.00', 2) !== 1) {
                $sinTotal++;

                continue;
            }

            $aportes = $this->calculator->calcularTrabajador($vista['total_ganado']);
            $filas[] = [
                'personal_id' => $empleado->id,
                'nombre' => $vista['nombre'],
                'numero_cua' => $vista['numero_cua'],
                'dias_trabajados' => $vista['dias_trabajados'],
                ...$aportes,
            ];
        }

        $totales = $this->calculator->sumarFilas($filas);

        return [
            'periodo' => [
                'anio' => $anio,
                'mes' => $mes,
                'etiqueta' => $this->etiquetaPeriodo($anio, $mes),
            ],
            'filas' => $filas,
            'totales' => $totales,
            'consolidado' => $this->calculator->consolidar($totales),
            'omitidos' => [
                'deshabilitados' => $deshabilitados,
                'sin_total_ganado' => $sinTotal,
            ],
            'trabajadores' => count($filas),
        ];
    }

    private function consultaPeriodo(int $empresaId, int $anio, int $mes, ?string $busqueda): Builder
    {
        $inicio = Carbon::create($anio, $mes, 1)->toDateString();
        $fin = Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString();

        $consulta = Personal::query()
            ->where('empresa_id', $empresaId)
            ->where('estado', '!=', 'inactivo')
            ->whereDate('fecha_ingreso', '<=', $fin)
            ->where(function (Builder $query) use ($inicio): void {
                $query->whereNull('fecha_egreso')
                    ->orWhereDate('fecha_egreso', '>=', $inicio);
            })
            ->with(['gestoraPeriodos' => function ($query) use ($anio, $mes): void {
                $query->where('anio', $anio)->where('mes', $mes);
            }]);

        $texto = trim((string) $busqueda);
        if ($texto !== '') {
            $consulta->busqueda($texto);
        }

        return $consulta->orderBy('apellidos')->orderBy('nombres');
    }

    /**
     * @return array<string, mixed>
     */
    private function textoCelda(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }

        if (is_int($valor)) {
            return (string) $valor;
        }

        if (is_float($valor)) {
            return rtrim(rtrim(sprintf('%.2f', $valor), '0'), '.');
        }

        return trim((string) $valor);
    }

    private function filaListado(Personal $personal): array
    {
        /** @var PersonalGestoraPeriodo|null $periodo */
        $periodo = $personal->gestoraPeriodos->first();
        $total = $periodo?->total_ganado ?? $personal->salario_mensual ?? '0.00';

        return [
            'id' => $personal->id,
            'nombres' => $personal->nombres,
            'apellidos' => $personal->apellidos,
            'nombre' => trim($personal->nombres.' '.$personal->apellidos),
            'ci' => $personal->ci,
            'numero_cua' => $personal->numero_cua,
            'dias_trabajados' => $periodo?->dias_trabajados ?? self::DIAS_POR_DEFECTO,
            'total_ganado' => $this->calculator->normalizarMonto($total),
            'habilitado' => $periodo?->habilitado ?? true,
            'periodo_guardado' => $periodo !== null,
        ];
    }

    /**
     * @param  list<int>  $personalIds
     */
    private function hayDeshabilitados(array $personalIds, int $anio, int $mes): bool
    {
        return PersonalGestoraPeriodo::query()
            ->whereIn('personal_id', $personalIds)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->where('habilitado', false)
            ->exists();
    }

    private function cuaOcupado(int $empresaId, string $cua, int $exceptoId): bool
    {
        return Personal::query()
            ->where('empresa_id', $empresaId)
            ->where('numero_cua', $cua)
            ->where('id', '!=', $exceptoId)
            ->exists();
    }
}
