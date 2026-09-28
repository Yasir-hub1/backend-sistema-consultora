<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alerta;
use App\Models\EmpresaCliente;
use App\Models\Personal;
use App\Models\PersonalCaja;
use App\Services\ColaboradorAutorizacionService;
use App\Services\CumplimientoModuloService;
use App\Services\GestoraPlanillaService;
use App\Services\PersonalRegistroService;
use App\Support\NumeroCua;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PersonalController extends ApiController
{
    public function __construct(
        private PersonalRegistroService $personalRegistroService,
        private CumplimientoModuloService $cumplimientoModuloService,
        private GestoraPlanillaService $gestoraPlanillaService,
    ) {}

    private function empresaAccesible(Request $request, int $empresaId): ?EmpresaCliente
    {
        return ColaboradorAutorizacionService::empresaAccesible($request->user(), $empresaId);
    }

    private function decodeContactosReferencia(Request $request): ?array
    {
        $raw = $request->input('contactos_referencia');
        if ($raw === null) {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    private function saveLegajoArchivo(?UploadedFile $file, int $empresaClienteId, int $personalId, string $folder): ?array
    {
        if (! $file) {
            return null;
        }

        $path = $file->store("personal/empresa_{$empresaClienteId}/{$personalId}/{$folder}", 'public');

        return [
            'path' => $path,
            'nombre' => $file->getClientOriginalName(),
        ];
    }

    private function withLegajoFileUrls(Personal $per): Personal
    {
        $per->setAttribute(
            'curriculum_archivo_url',
            $per->curriculum_archivo_path ? Storage::url($per->curriculum_archivo_path) : null
        );
        $per->setAttribute(
            'licencia_conducir_archivo_url',
            $per->licencia_conducir_archivo_path ? Storage::url($per->licencia_conducir_archivo_path) : null
        );
        $per->setAttribute(
            'aviso_luz_agua_archivo_url',
            $per->aviso_luz_agua_archivo_path ? Storage::url($per->aviso_luz_agua_archivo_path) : null
        );
        $per->setAttribute(
            'croquis_archivo_url',
            $per->croquis_archivo_path ? Storage::url($per->croquis_archivo_path) : null
        );
        $per->setAttribute(
            'certificado_nacimiento_archivo_url',
            $per->certificado_nacimiento_archivo_path ? Storage::url($per->certificado_nacimiento_archivo_path) : null
        );

        return $per;
    }

    public function index(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $q = Personal::query()
            ->where('empresa_id', $empresaClienteId)
            ->with(['afp', 'caja', 'ministerio']);

        if ($s = $request->get('search')) {
            $s = trim((string) $s);
            if ($s !== '') {
                $q->busqueda($s);
            }
        }

        $p = $q->orderBy('id', 'desc')->paginate(min((int) $request->get('per_page', 15), 100));

        $empresa = EmpresaCliente::query()->find($empresaClienteId);
        $totalPersonal = Personal::query()->where('empresa_id', $empresaClienteId)->count();

        $items = collect($p->items())->map(function (Personal $per) {
            return [
                'id' => $per->id,
                'nombres' => $per->nombres,
                'apellidos' => $per->apellidos,
                'ci' => $per->ci,
                'cargo' => $per->cargo,
                'estado_afp' => $per->afp?->estado,
                'estado_caja' => $per->caja?->estado,
                'estado_ministerio' => $per->ministerio?->estado,
                'personal_afp' => $per->afp,
                'personal_caja' => $per->caja,
                'personal_ministerio' => $per->ministerio,
            ];
        })->all();

        return $this->ok([
            'empresa' => $empresa ? [
                'id' => $empresa->id,
                'nombre' => $empresa->nombre,
                'razon_social' => $empresa->razon_social,
                'nit' => $empresa->nit,
            ] : null,
            'data' => $items,
            'current_page' => $p->currentPage(),
            'last_page' => $p->lastPage(),
            'total' => $p->total(),
            'stats' => [
                'total_personal' => $totalPersonal,
            ],
        ]);
    }

    public function show(Request $request, int $empresaClienteId, int $personalId): JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $per = Personal::query()
            ->where('empresa_id', $empresaClienteId)
            ->with(['afp', 'caja', 'ministerio', 'documentos.tipoDocumento', 'empresaCliente'])
            ->find($personalId);

        if (! $per) {
            return $this->fail('No encontrado', 404);
        }

        return $this->ok($this->withLegajoFileUrls($per));
    }

    /**
     * Sirve curriculum, licencia, avisos, croquis y certificado con token (evita 404 al abrir /storage en otro origen).
     */
    public function streamLegajoArchivo(Request $request, int $empresaClienteId, int $personalId, string $tipo): Response|JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $allowed = ['curriculum', 'licencia', 'aviso', 'croquis', 'certificado_nacimiento'];
        if (! in_array($tipo, $allowed, true)) {
            return $this->fail('Tipo de archivo no permitido.', 422);
        }

        $per = Personal::query()
            ->where('empresa_id', $empresaClienteId)
            ->find($personalId);

        if (! $per) {
            return $this->fail('No encontrado', 404);
        }

        $map = [
            'curriculum' => ['path' => $per->curriculum_archivo_path, 'nombre' => $per->curriculum_archivo_nombre],
            'licencia' => ['path' => $per->licencia_conducir_archivo_path, 'nombre' => $per->licencia_conducir_archivo_nombre],
            'aviso' => ['path' => $per->aviso_luz_agua_archivo_path, 'nombre' => $per->aviso_luz_agua_archivo_nombre],
            'croquis' => ['path' => $per->croquis_archivo_path, 'nombre' => $per->croquis_archivo_nombre],
            'certificado_nacimiento' => ['path' => $per->certificado_nacimiento_archivo_path, 'nombre' => $per->certificado_nacimiento_archivo_nombre],
        ];

        $path = $map[$tipo]['path'];
        $nombre = $map[$tipo]['nombre'] ?: 'archivo';
        if (! $path) {
            return $this->fail('No hay archivo para este ítem.', 404);
        }

        if (! Storage::disk('public')->exists($path)) {
            return $this->fail('El archivo no existe en almacenamiento.', 404);
        }

        $mime = 'application/pdf';
        if ($tipo === 'certificado_nacimiento') {
            $ext = strtolower(pathinfo((string) $nombre, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'application/pdf',
            };
        }

        return Storage::disk('public')->response($path, $nombre, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$nombre.'"',
        ]);
    }

    public function patchRegimenCaja(Request $request, int $empresaClienteId, int $personalId): JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeEditarPersonal($request->user(), $empresaClienteId)) {
            return $this->fail('No autorizado para editar datos de personal.', 403);
        }

        $data = $request->validate([
            'regimen_caja' => ['required', 'string', 'in:nacional,petrolera'],
        ]);

        $per = Personal::query()
            ->where('empresa_id', $empresaClienteId)
            ->find($personalId);

        if (! $per) {
            return $this->fail('No encontrado', 404);
        }

        $caja = PersonalCaja::query()->firstOrCreate(
            ['personal_id' => $per->id],
            ['estado' => 'sin_datos']
        );
        $caja->regimen_caja = $data['regimen_caja'];
        $caja->save();

        $this->cumplimientoModuloService->recalcularPersonal($per, 'caja');

        return $this->ok($per->fresh()->load(['afp', 'caja', 'ministerio']));
    }

    public function store(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeRegistrarPersonal($request->user(), $empresaClienteId)) {
            return $this->fail('No autorizado para registrar personal.', 403);
        }

        $contactosReferencia = $this->decodeContactosReferencia($request);
        if ($contactosReferencia !== null) {
            $request->merge(['contactos_referencia' => $contactosReferencia]);
        }

        $data = $request->validate([
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'max:20'],
            'numero_cua' => ['nullable', 'string', 'max:40'],
            'dias_trabajados' => ['nullable', 'integer', 'min:0', 'max:31'],
            'total_ganado' => ['nullable', 'numeric', 'min:0'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'cargo' => ['nullable', 'string', 'max:150'],
            'fecha_ingreso' => ['required', 'date'],
            'afp_id' => ['nullable'],
            'nro_afp' => ['nullable', 'string', 'max:50'],
            'caja_id' => ['nullable'],
            'nro_caja' => ['nullable', 'string', 'max:50'],
            'correo_electronico' => ['nullable', 'email', 'max:150'],
            'cuenta_bancaria' => ['nullable', 'string', 'max:120'],
            'contactos_referencia' => ['nullable', 'array', 'min:2', 'max:3'],
            'contactos_referencia.*' => ['nullable', 'string', 'max:160'],
            'curriculum_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'licencia_conducir_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'aviso_luz_agua_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'croquis_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'certificado_nacimiento_archivo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        if (Personal::query()->where('empresa_id', $empresaClienteId)->where('ci', $data['ci'])->exists()) {
            return $this->fail('CI ya registrado en esta empresa.', 422);
        }

        $numeroCua = $this->resolverNumeroCua($request->input('numero_cua'), $empresaClienteId);
        if ($numeroCua instanceof JsonResponse) {
            return $numeroCua;
        }

        $periodoGestora = $this->gestoraPlanillaService->normalizarPeriodoInicial(
            $request->input('dias_trabajados'),
            $request->input('total_ganado'),
        );
        if (isset($periodoGestora['error'])) {
            return $this->fail($periodoGestora['error'], 422);
        }

        $colabId = $request->user()->colaborador?->id;

        $per = Personal::create([
            'empresa_id' => $empresaClienteId,
            'registrado_por' => $colabId,
            'nombres' => $data['nombres'],
            'apellidos' => $data['apellidos'],
            'ci' => $data['ci'],
            'numero_cua' => $numeroCua,
            'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
            'cargo' => $data['cargo'] ?? 'Personal',
            'fecha_ingreso' => $data['fecha_ingreso'],
            'correo_electronico' => $data['correo_electronico'] ?? null,
            'cuenta_bancaria' => $data['cuenta_bancaria'] ?? null,
            'contactos_referencia' => $data['contactos_referencia'] ?? null,
        ]);

        $curriculum = $this->saveLegajoArchivo($request->file('curriculum_archivo'), $empresaClienteId, $per->id, 'curriculum');
        $licencia = $this->saveLegajoArchivo($request->file('licencia_conducir_archivo'), $empresaClienteId, $per->id, 'licencia');
        $aviso = $this->saveLegajoArchivo($request->file('aviso_luz_agua_archivo'), $empresaClienteId, $per->id, 'aviso_luz_agua');
        $croquis = $this->saveLegajoArchivo($request->file('croquis_archivo'), $empresaClienteId, $per->id, 'croquis');
        $certNacimiento = $this->saveLegajoArchivo($request->file('certificado_nacimiento_archivo'), $empresaClienteId, $per->id, 'certificado_nacimiento');

        if ($curriculum) {
            $per->curriculum_archivo_path = $curriculum['path'];
            $per->curriculum_archivo_nombre = $curriculum['nombre'];
        }
        if ($licencia) {
            $per->licencia_conducir_archivo_path = $licencia['path'];
            $per->licencia_conducir_archivo_nombre = $licencia['nombre'];
        }
        if ($aviso) {
            $per->aviso_luz_agua_archivo_path = $aviso['path'];
            $per->aviso_luz_agua_archivo_nombre = $aviso['nombre'];
        }
        if ($croquis) {
            $per->croquis_archivo_path = $croquis['path'];
            $per->croquis_archivo_nombre = $croquis['nombre'];
        }
        if ($certNacimiento) {
            $per->certificado_nacimiento_archivo_path = $certNacimiento['path'];
            $per->certificado_nacimiento_archivo_nombre = $certNacimiento['nombre'];
        }
        $per->save();

        $this->personalRegistroService->crearConModulos($per, [
            'numero_afiliado' => $data['nro_afp'] ?? null,
        ], [
            'numero_asegurado' => $data['nro_caja'] ?? null,
        ]);

        if ($periodoGestora['omitir'] !== true) {
            $this->gestoraPlanillaService->guardarPeriodoInicial($per, $periodoGestora['dias'], $periodoGestora['total']);
        }

        $empresa = EmpresaCliente::query()->find($empresaClienteId);
        if ($empresa) {
            $alreadyNotified = Alerta::query()
                ->where('consultora_id', $empresa->consultora_id)
                ->where('empresa_id', $empresa->id)
                ->where('personal_id', $per->id)
                ->where('modulo', 'registro_personal')
                ->where('resuelta', false)
                ->exists();

            if (! $alreadyNotified) {
                Alerta::create([
                    'consultora_id' => $empresa->consultora_id,
                    'empresa_id' => $empresa->id,
                    'personal_id' => $per->id,
                    'modulo' => 'registro_personal',
                    'nivel' => 'normal',
                    'titulo' => 'Nuevo personal registrado',
                    'descripcion' => "{$per->nombres} {$per->apellidos} fue registrado en {$empresa->nombre}.",
                    'generada_auto' => true,
                    'contexto' => [
                        'paths' => [
                            'consultora' => '/consultora/mis-empresas/'.$empresa->id,
                            'empresa_cliente' => '/empresa-cliente/personal/'.$per->id,
                        ],
                    ],
                ]);
            }
        }

        return $this->ok($this->withLegajoFileUrls($per->load(['afp', 'caja', 'ministerio'])), 'Personal creado', 201);
    }

    public function update(Request $request, int $empresaClienteId, int $personalId): JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeEditarPersonal($request->user(), $empresaClienteId)) {
            return $this->fail('No autorizado para editar personal.', 403);
        }

        $per = Personal::query()
            ->where('empresa_id', $empresaClienteId)
            ->find($personalId);

        if (! $per) {
            return $this->fail('No encontrado', 404);
        }

        $contactosReferencia = $this->decodeContactosReferencia($request);
        if ($contactosReferencia !== null) {
            $request->merge(['contactos_referencia' => $contactosReferencia]);
        }

        $data = $request->validate([
            'nombres' => ['sometimes', 'string', 'max:100'],
            'apellidos' => ['sometimes', 'string', 'max:100'],
            'ci' => ['sometimes', 'string', 'max:20'],
            'numero_cua' => ['sometimes', 'nullable', 'string', 'max:40'],
            'extension_ci' => ['nullable', 'string', 'max:4'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'genero' => ['nullable', 'string', 'max:32'],
            'estado_civil' => ['nullable', 'string', 'max:32'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:150'],
            'direccion' => ['nullable', 'string'],
            'nivel_educacion' => ['nullable', 'string', 'max:100'],
            'profesion' => ['nullable', 'string', 'max:150'],
            'cargo' => ['sometimes', 'string', 'max:150'],
            'fecha_ingreso' => ['sometimes', 'date'],
            'fecha_egreso' => ['nullable', 'date'],
            'tipo_contrato' => ['nullable', 'string', 'max:64'],
            'salario_mensual' => ['nullable', 'numeric'],
            'modalidad' => ['nullable', 'string', 'max:64'],
            'estado' => ['nullable', 'string', 'max:32'],
            'observaciones' => ['nullable', 'string'],
            'correo_electronico' => ['nullable', 'email', 'max:150'],
            'cuenta_bancaria' => ['nullable', 'string', 'max:120'],
            'contactos_referencia' => ['nullable', 'array', 'min:2', 'max:3'],
            'contactos_referencia.*' => ['nullable', 'string', 'max:160'],
            'curriculum_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'licencia_conducir_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'aviso_luz_agua_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'croquis_archivo' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'certificado_nacimiento_archivo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        if ($data === []) {
            return $this->fail('No hay datos para actualizar.', 422);
        }

        if (isset($data['ci'])) {
            $dup = Personal::query()
                ->where('empresa_id', $empresaClienteId)
                ->where('ci', $data['ci'])
                ->where('id', '!=', $per->id)
                ->exists();
            if ($dup) {
                return $this->fail('CI ya registrado en esta empresa.', 422);
            }
        }

        if ($request->exists('numero_cua')) {
            $numeroCua = $this->resolverNumeroCua($request->input('numero_cua'), $empresaClienteId, $per->id);
            if ($numeroCua instanceof JsonResponse) {
                return $numeroCua;
            }
            $data['numero_cua'] = $numeroCua;
        }

        $per->fill($data);

        $curriculum = $this->saveLegajoArchivo($request->file('curriculum_archivo'), $empresaClienteId, $per->id, 'curriculum');
        if ($curriculum) {
            Storage::disk('public')->delete((string) $per->curriculum_archivo_path);
            $per->curriculum_archivo_path = $curriculum['path'];
            $per->curriculum_archivo_nombre = $curriculum['nombre'];
        }
        $licencia = $this->saveLegajoArchivo($request->file('licencia_conducir_archivo'), $empresaClienteId, $per->id, 'licencia');
        if ($licencia) {
            Storage::disk('public')->delete((string) $per->licencia_conducir_archivo_path);
            $per->licencia_conducir_archivo_path = $licencia['path'];
            $per->licencia_conducir_archivo_nombre = $licencia['nombre'];
        }
        $aviso = $this->saveLegajoArchivo($request->file('aviso_luz_agua_archivo'), $empresaClienteId, $per->id, 'aviso_luz_agua');
        if ($aviso) {
            Storage::disk('public')->delete((string) $per->aviso_luz_agua_archivo_path);
            $per->aviso_luz_agua_archivo_path = $aviso['path'];
            $per->aviso_luz_agua_archivo_nombre = $aviso['nombre'];
        }
        $croquis = $this->saveLegajoArchivo($request->file('croquis_archivo'), $empresaClienteId, $per->id, 'croquis');
        if ($croquis) {
            Storage::disk('public')->delete((string) $per->croquis_archivo_path);
            $per->croquis_archivo_path = $croquis['path'];
            $per->croquis_archivo_nombre = $croquis['nombre'];
        }
        $certNacimiento = $this->saveLegajoArchivo($request->file('certificado_nacimiento_archivo'), $empresaClienteId, $per->id, 'certificado_nacimiento');
        if ($certNacimiento) {
            Storage::disk('public')->delete((string) $per->certificado_nacimiento_archivo_path);
            $per->certificado_nacimiento_archivo_path = $certNacimiento['path'];
            $per->certificado_nacimiento_archivo_nombre = $certNacimiento['nombre'];
        }

        $per->save();

        return $this->ok($this->withLegajoFileUrls($per->fresh()->load(['afp', 'caja', 'ministerio', 'empresaCliente'])));
    }

    public function descargarPlantillaRegistroMasivo(Request $request, int $empresaClienteId): StreamedResponse|JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeRegistrarPersonal($request->user(), $empresaClienteId)) {
            return $this->fail('No autorizado para registrar personal.', 403);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plantilla');
        $headers = [
            'NOMBRES',
            'APELLIDOS',
            'CI',
            'NRO_CUA',
            'DIAS_TRABAJADOS',
            'TOTAL_GANADO',
            'FECHA_NACIMIENTO(YYYY-MM-DD)',
            'FECHA_INGRESO(YYYY-MM-DD)',
            'CARGO',
            'CORREO_ELECTRONICO',
            'CUENTA_BANCARIA',
            'CONTACTO_REFERENCIA_1',
            'CONTACTO_REFERENCIA_2',
            'CONTACTO_REFERENCIA_3',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([
            'Juan',
            'Pérez',
            '1234567 LP',
            '10000001',
            '30',
            '8500.00',
            '',
            now()->toDateString(),
            'Personal',
            'juan.perez@mail.com',
            '',
            'María López 70123456',
            'Pedro Rojas 70234567',
            '',
        ], null, 'A2');

        foreach (range(1, count($headers)) as $columnIndex) {
            $col = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 'plantilla_registro_masivo_personal.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function cargarRegistroMasivo(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        if (! ColaboradorAutorizacionService::puedeRegistrarPersonal($request->user(), $empresaClienteId)) {
            return $this->fail('No autorizado para registrar personal.', 403);
        }

        $request->validate([
            'archivo' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv'],
        ]);

        $file = $request->file('archivo');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);
        if (count($rows) < 2) {
            return $this->fail('La plantilla no contiene filas para procesar.', 422);
        }

        $header = array_map(static fn ($v) => trim((string) $v), $rows[1] ?? []);
        $map = [];
        foreach ($header as $col => $name) {
            $map[Str::upper($name)] = $col;
        }

        $required = [
            'NOMBRES',
            'APELLIDOS',
            'CI',
            'FECHA_INGRESO(YYYY-MM-DD)',
            'CONTACTO_REFERENCIA_1',
            'CONTACTO_REFERENCIA_2',
        ];
        foreach ($required as $rh) {
            if (! isset($map[$rh])) {
                return $this->fail("Falta columna obligatoria en plantilla: {$rh}.", 422);
            }
        }

        $creados = 0;
        $errores = [];
        $colabId = $request->user()->colaborador?->id;

        for ($i = 2; $i <= count($rows); $i++) {
            $line = $rows[$i] ?? [];

            $c1 = trim((string) ($line[$map['CONTACTO_REFERENCIA_1']] ?? ''));
            $c2 = trim((string) ($line[$map['CONTACTO_REFERENCIA_2']] ?? ''));
            $c3 = isset($map['CONTACTO_REFERENCIA_3']) ? trim((string) ($line[$map['CONTACTO_REFERENCIA_3']] ?? '')) : '';

            $contactos = array_values(array_filter([$c1, $c2, $c3], static fn ($v) => $v !== ''));

            $fnRaw = isset($map['FECHA_NACIMIENTO(YYYY-MM-DD)'])
                ? trim((string) ($line[$map['FECHA_NACIMIENTO(YYYY-MM-DD)']] ?? ''))
                : '';

            $nombres = trim((string) ($line[$map['NOMBRES']] ?? ''));
            $apellidos = trim((string) ($line[$map['APELLIDOS']] ?? ''));
            $ci = trim((string) ($line[$map['CI']] ?? ''));
            if ($nombres === '' && $apellidos === '' && $ci === '') {
                continue;
            }

            $columnaCua = $map['NRO_CUA'] ?? $map['NRO_RUA'] ?? $map['CUA_RUA'] ?? $map['RUA'] ?? null;
            $cuaCrudo = $columnaCua !== null ? ($line[$columnaCua] ?? null) : null;
            $numeroCua = $this->resolverNumeroCua($cuaCrudo, $empresaClienteId);
            if ($numeroCua instanceof JsonResponse) {
                $mensaje = json_decode((string) $numeroCua->getContent(), true);
                $errores[] = ['fila' => $i, 'mensaje' => is_array($mensaje) ? ($mensaje['message'] ?? 'CUA/RUA inválido.') : 'CUA/RUA inválido.'];

                continue;
            }

            $periodoGestora = $this->gestoraPlanillaService->normalizarPeriodoInicial(
                isset($map['DIAS_TRABAJADOS']) ? ($line[$map['DIAS_TRABAJADOS']] ?? null) : null,
                isset($map['TOTAL_GANADO']) ? ($line[$map['TOTAL_GANADO']] ?? null) : null,
            );
            if (isset($periodoGestora['error'])) {
                $errores[] = ['fila' => $i, 'mensaje' => $periodoGestora['error']];

                continue;
            }

            $payload = [
                'nombres' => $nombres,
                'apellidos' => $apellidos,
                'ci' => $ci,
                'numero_cua' => $numeroCua,
                'fecha_nacimiento' => $fnRaw === '' ? null : $fnRaw,
                'fecha_ingreso' => trim((string) ($line[$map['FECHA_INGRESO(YYYY-MM-DD)']] ?? '')),
                'cargo' => isset($map['CARGO']) ? trim((string) ($line[$map['CARGO']] ?? '')) : '',
                'correo_electronico' => isset($map['CORREO_ELECTRONICO'])
                    ? trim((string) ($line[$map['CORREO_ELECTRONICO']] ?? ''))
                    : '',
                'cuenta_bancaria' => isset($map['CUENTA_BANCARIA'])
                    ? trim((string) ($line[$map['CUENTA_BANCARIA']] ?? ''))
                    : '',
                'contactos_referencia' => $contactos,
            ];

            $validator = Validator::make($payload, [
                'nombres' => ['required', 'string', 'max:100'],
                'apellidos' => ['required', 'string', 'max:100'],
                'ci' => ['required', 'string', 'max:20'],
                'fecha_nacimiento' => ['nullable', 'date'],
                'fecha_ingreso' => ['required', 'date'],
                'cargo' => ['nullable', 'string', 'max:150'],
                'correo_electronico' => ['nullable', 'email', 'max:150'],
                'cuenta_bancaria' => ['nullable', 'string', 'max:120'],
                'contactos_referencia' => ['required', 'array', 'min:2', 'max:3'],
                'contactos_referencia.*' => ['required', 'string', 'max:160'],
            ]);

            if ($validator->fails()) {
                $errores[] = [
                    'fila' => $i,
                    'mensaje' => collect($validator->errors()->all())->join(' | '),
                ];

                continue;
            }

            if (Personal::query()->where('empresa_id', $empresaClienteId)->where('ci', $payload['ci'])->exists()) {
                $errores[] = ['fila' => $i, 'mensaje' => 'CI ya registrado en esta empresa.'];

                continue;
            }

            try {
                DB::transaction(function () use ($payload, $empresaClienteId, $colabId, $periodoGestora) {
                    $per = Personal::create([
                        'empresa_id' => $empresaClienteId,
                        'registrado_por' => $colabId,
                        'nombres' => $payload['nombres'],
                        'apellidos' => $payload['apellidos'],
                        'ci' => $payload['ci'],
                        'numero_cua' => $payload['numero_cua'],
                        'fecha_nacimiento' => $payload['fecha_nacimiento']
                            ? Carbon::parse($payload['fecha_nacimiento'])
                            : null,
                        'cargo' => $payload['cargo'] !== '' ? $payload['cargo'] : 'Personal',
                        'fecha_ingreso' => Carbon::parse($payload['fecha_ingreso']),
                        'correo_electronico' => $payload['correo_electronico'] !== '' ? $payload['correo_electronico'] : null,
                        'cuenta_bancaria' => $payload['cuenta_bancaria'] !== '' ? $payload['cuenta_bancaria'] : null,
                        'contactos_referencia' => $payload['contactos_referencia'],
                    ]);

                    $this->personalRegistroService->crearConModulos($per, [], []);

                    if ($periodoGestora['omitir'] !== true) {
                        $this->gestoraPlanillaService->guardarPeriodoInicial(
                            $per,
                            $periodoGestora['dias'],
                            $periodoGestora['total'],
                        );
                    }

                    $empresa = EmpresaCliente::query()->find($empresaClienteId);
                    if ($empresa) {
                        $alreadyNotified = Alerta::query()
                            ->where('consultora_id', $empresa->consultora_id)
                            ->where('empresa_id', $empresa->id)
                            ->where('personal_id', $per->id)
                            ->where('modulo', 'registro_personal')
                            ->where('resuelta', false)
                            ->exists();

                        if (! $alreadyNotified) {
                            Alerta::create([
                                'consultora_id' => $empresa->consultora_id,
                                'empresa_id' => $empresa->id,
                                'personal_id' => $per->id,
                                'modulo' => 'registro_personal',
                                'nivel' => 'normal',
                                'titulo' => 'Nuevo personal registrado',
                                'descripcion' => "{$per->nombres} {$per->apellidos} fue registrado en {$empresa->nombre}.",
                                'generada_auto' => true,
                                'contexto' => [
                                    'paths' => [
                                        'consultora' => '/consultora/mis-empresas/'.$empresa->id,
                                        'empresa_cliente' => '/empresa-cliente/personal/'.$per->id,
                                    ],
                                ],
                            ]);
                        }
                    }
                });
                $creados++;
            } catch (\Throwable) {
                $errores[] = ['fila' => $i, 'mensaje' => 'Error al crear personal en esta fila.'];
            }
        }

        return $this->ok([
            'creados' => $creados,
            'errores' => $errores,
            'procesados' => $creados + count($errores),
        ], $creados > 0 ? 'Carga masiva procesada.' : 'No se pudo crear ningún registro.');
    }

    /**
     * @return string|null|JsonResponse null cuando el campo viene vacío
     */
    private function resolverNumeroCua(mixed $raw, int $empresaId, ?int $exceptoId = null): string|null|JsonResponse
    {
        $resuelto = NumeroCua::resolver($raw);
        if ($resuelto['error'] !== null) {
            return $this->fail($resuelto['error'], 422);
        }

        if ($resuelto['valor'] !== null) {
            $ocupado = Personal::query()
                ->where('empresa_id', $empresaId)
                ->where('numero_cua', $resuelto['valor'])
                ->when($exceptoId !== null, fn ($query) => $query->where('id', '!=', $exceptoId))
                ->exists();
            if ($ocupado) {
                return $this->fail('El CUA/RUA ya está registrado en esta empresa.', 422);
            }
        }

        return $resuelto['valor'];
    }
}
