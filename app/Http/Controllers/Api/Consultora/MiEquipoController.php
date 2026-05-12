<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\Colaborador;
use App\Models\ColaboradorPermiso;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MiEquipoController extends ApiController
{
    private const CARGOS_VALIDOS = [
        'coordinador_general',
        'analista_afp',
        'analista_caja',
        'analista_ministerio',
        'asistente',
    ];

    public function descargarPlantillaRegistroMasivo(Request $request): StreamedResponse|JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plantilla');
        $headers = [
            'NOMBRES',
            'APELLIDOS',
            'CI',
            'TELEFONO',
            'CORREO',
            'CARGO',
            'FECHA_INGRESO(YYYY-MM-DD)',
            'CONTRASENA_INICIAL',
            'ACCESO_HABILITADO(1/0)',
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([
            'Juan',
            'Perez',
            '1234567',
            '70123456',
            'juan.perez@empresa.com',
            'coordinador_general',
            now()->toDateString(),
            'Temporal123',
            '1',
        ], null, 'A2');

        foreach (range(1, count($headers)) as $columnIndex) {
            $col = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        $catalogSheet = $spreadsheet->createSheet();
        $catalogSheet->setTitle('CATALOGOS');
        $catalogSheet->setCellValue('A1', 'CARGOS_VALIDOS');
        foreach (self::CARGOS_VALIDOS as $idx => $cargo) {
            $catalogSheet->setCellValue('A'.($idx + 2), $cargo);
        }
        $catalogSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        for ($row = 2; $row <= 300; $row++) {
            $validation = $sheet->getCell("F{$row}")->getDataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            $validation->setAllowBlank(false);
            $validation->setShowInputMessage(true);
            $validation->setShowErrorMessage(true);
            $validation->setShowDropDown(true);
            $validation->setErrorTitle('Cargo inválido');
            $validation->setError('Selecciona un cargo de la lista.');
            $validation->setFormula1('=CATALOGOS!$A$2:$A$6');
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 'plantilla_registro_masivo_colaboradores.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function cargarRegistroMasivo(Request $request): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
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

        $requiredHeaders = ['NOMBRES', 'APELLIDOS', 'CI', 'CORREO', 'CARGO', 'CONTRASENA_INICIAL'];
        foreach ($requiredHeaders as $rh) {
            if (! isset($map[$rh])) {
                return $this->fail("Falta columna obligatoria en plantilla: {$rh}.", 422);
            }
        }

        $creados = 0;
        $errores = [];
        for ($i = 2; $i <= count($rows); $i++) {
            $line = $rows[$i] ?? [];
            $payload = [
                'nombres' => trim((string) ($line[$map['NOMBRES']] ?? '')),
                'apellidos' => trim((string) ($line[$map['APELLIDOS']] ?? '')),
                'ci' => trim((string) ($line[$map['CI']] ?? '')),
                'telefono' => trim((string) ($line[$map['TELEFONO']] ?? '')),
                'correo' => trim((string) ($line[$map['CORREO']] ?? '')),
                'cargo' => trim((string) ($line[$map['CARGO']] ?? '')),
                'fecha_ingreso' => trim((string) ($line[$map['FECHA_INGRESO(YYYY-MM-DD)']] ?? '')),
                'password' => trim((string) ($line[$map['CONTRASENA_INICIAL']] ?? '')),
                'acceso_habilitado' => trim((string) ($line[$map['ACCESO_HABILITADO(1/0)']] ?? '1')),
            ];

            $emptyRow = collect($payload)->every(fn ($v) => $v === '' || $v === null);
            if ($emptyRow) {
                continue;
            }

            $validator = Validator::make($payload, [
                'nombres' => ['required', 'string', 'max:100'],
                'apellidos' => ['required', 'string', 'max:100'],
                'ci' => ['required', 'string', 'max:20'],
                'telefono' => ['nullable', 'string', 'max:20'],
                'correo' => ['required', 'email', 'max:150'],
                'cargo' => ['required', Rule::in(self::CARGOS_VALIDOS)],
                'fecha_ingreso' => ['nullable', 'date'],
                'password' => ['required', 'string', 'min:8'],
                'acceso_habilitado' => ['nullable', Rule::in(['1', '0', 'true', 'false', ''])],
            ]);

            if ($validator->fails()) {
                $errores[] = [
                    'fila' => $i,
                    'mensaje' => collect($validator->errors()->all())->join(' | '),
                ];
                continue;
            }

            if (Usuario::query()->where('correo', $payload['correo'])->exists()) {
                $errores[] = ['fila' => $i, 'mensaje' => 'El correo ya existe.'];
                continue;
            }

            $accesoHabilitado = in_array(Str::lower((string) $payload['acceso_habilitado']), ['1', 'true', ''], true);
            try {
                DB::transaction(function () use ($payload, $e, $accesoHabilitado) {
                    $nuBase = Str::slug(Str::before($payload['correo'], '@')) ?: 'colab';
                    $nu = $nuBase;
                    $n = 0;
                    while (Usuario::query()->where('nombre_usuario', $nu)->exists()) {
                        $nu = $nuBase.++$n;
                    }

                    $usuario = Usuario::create([
                        'nombre_usuario' => $nu,
                        'correo' => $payload['correo'],
                        'contrasena_hash' => Hash::make($payload['password']),
                        'tipo' => 'colaborador',
                        'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
                        'verificado' => $accesoHabilitado,
                        'debe_cambiar_contrasena' => $accesoHabilitado,
                    ]);

                    $col = Colaborador::create([
                        'consultora_id' => $e->id,
                        'usuario_id' => $usuario->id,
                        'nombres' => $payload['nombres'],
                        'apellidos' => $payload['apellidos'],
                        'ci' => $payload['ci'],
                        'telefono' => $payload['telefono'] ?: null,
                        'cargo' => $payload['cargo'],
                        'fecha_ingreso' => $payload['fecha_ingreso'] ? Carbon::parse($payload['fecha_ingreso']) : now(),
                        'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
                    ]);

                    $this->seedPermisos($col, $payload['cargo'], $e->id);
                });
                $creados++;
            } catch (\Throwable $th) {
                $errores[] = ['fila' => $i, 'mensaje' => 'Error al crear colaborador en esta fila.'];
            }
        }

        return $this->ok([
            'creados' => $creados,
            'errores' => $errores,
            'procesados' => $creados + count($errores),
        ], $creados > 0 ? 'Carga masiva procesada.' : 'No se pudo crear ningún colaborador.');
    }

    public function index(Request $request): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $consultoraId = $e->id;

        $q = Colaborador::query()
            ->where('consultora_id', $consultoraId)
            ->with(['usuario', 'permisosPorModulo'])
            ->orderBy('id');

        if ($s = $request->get('search')) {
            $s = trim((string) $s);
            if ($s !== '') {
                $q->where(function ($w) use ($s) {
                    $like = '%'.$s.'%';
                    $w->where('nombres', 'like', $like)
                        ->orWhere('apellidos', 'like', $like)
                        ->orWhere('ci', 'like', $like)
                        ->orWhere('telefono', 'like', $like)
                        ->orWhere('cargo', 'like', $like)
                        ->orWhereHas('usuario', function ($uq) use ($like) {
                            $uq->where('correo', 'like', $like)
                                ->orWhere('nombre_usuario', 'like', $like);
                        });
                });
            }
        }

        $rows = $q->paginate(min((int) $request->get('per_page', 15), 100));

        $enEquipo = Colaborador::query()->where('consultora_id', $consultoraId)->count();
        $conPortalActivo = Colaborador::query()
            ->where('consultora_id', $consultoraId)
            ->whereHas('usuario', fn ($uq) => $uq->where('estado', '!=', 'inactivo'))
            ->count();

        $items = collect($rows->items())->map(function (Colaborador $c) {
            $row = $c->toArray();
            $u = $c->usuario;
            $row['acceso_habilitado'] = $u && $u->estado !== 'inactivo';
            $row['usuario_estado'] = $u?->estado;
            $row['debe_cambiar_contrasena'] = (bool) ($u?->debe_cambiar_contrasena);
            $row['puede_editar_empresa_cliente'] = (bool) $c->puede_editar_empresa_cliente;
            $row['permisos_por_modulo'] = $c->permisosPorModulo->map(static function ($p) {
                return [
                    'modulo' => $p->modulo,
                    'puede_ver' => (bool) $p->puede_ver,
                    'puede_registrar_personal' => (bool) $p->puede_registrar_personal,
                    'puede_editar_personal' => (bool) $p->puede_editar_personal,
                    'puede_subir_documentos' => (bool) $p->puede_subir_documentos,
                    'puede_eliminar_documentos' => (bool) $p->puede_eliminar_documentos,
                    'puede_gestionar_modulo' => (bool) $p->puede_gestionar_modulo,
                    'puede_exportar_reportes' => (bool) $p->puede_exportar_reportes,
                    'puede_invitar_empresa' => (bool) $p->puede_invitar_empresa,
                ];
            })->values()->all();

            return $row;
        })->all();

        return $this->ok([
            'data' => $items,
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'total' => $rows->total(),
            'stats' => [
                'en_equipo' => $enEquipo,
                'con_portal_activo' => $conPortalActivo,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $data = $request->validate([
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'correo' => ['required', 'email', 'max:150'],
            'cargo' => ['required', 'string', 'max:64'],
            'fecha_ingreso' => ['nullable', 'date'],
            'password' => ['required', 'string', 'min:8'],
            'acceso_habilitado' => ['sometimes', 'boolean'],
        ]);

        $accesoHabilitado = $data['acceso_habilitado'] ?? true;

        if (Usuario::query()->where('correo', $data['correo'])->exists()) {
            return $this->fail('El correo ya existe.', 422);
        }

        $nuBase = Str::slug(Str::before($data['correo'], '@')) ?: 'colab';
        $nu = $nuBase;
        $i = 0;
        while (Usuario::query()->where('nombre_usuario', $nu)->exists()) {
            $nu = $nuBase.++$i;
        }

        $usuario = Usuario::create([
            'nombre_usuario' => $nu,
            'correo' => $data['correo'],
            'contrasena_hash' => Hash::make($data['password']),
            'tipo' => 'colaborador',
            'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
            'verificado' => $accesoHabilitado,
            'debe_cambiar_contrasena' => $accesoHabilitado,
        ]);

        $col = Colaborador::create([
            'consultora_id' => $e->id,
            'usuario_id' => $usuario->id,
            'nombres' => $data['nombres'],
            'apellidos' => $data['apellidos'],
            'ci' => $data['ci'],
            'telefono' => $data['telefono'] ?? null,
            'cargo' => $data['cargo'],
            'fecha_ingreso' => $data['fecha_ingreso'] ?? now(),
            'estado' => $accesoHabilitado ? 'activo' : 'inactivo',
        ]);

        $this->seedPermisos($col, $data['cargo'], $e->id);

        return $this->ok([
            'colaborador' => $col->load('usuario'),
            'nombre_usuario' => $nu,
            'nota' => $accesoHabilitado
                ? 'El colaborador debe cambiar la contraseña en el primer acceso.'
                : 'Acceso deshabilitado; puede habilitarse desde el listado.',
        ], 'Colaborador creado', 201);
    }

    public function updateAcceso(Request $request, int $id): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $data = $request->validate([
            'acceso_habilitado' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $col = Colaborador::query()->where('consultora_id', $e->id)->whereKey($id)->first();
        if (! $col || ! $col->usuario) {
            return $this->fail('Colaborador no encontrado', 404);
        }

        $u = $col->usuario;

        if ($data['acceso_habilitado']) {
            $u->forceFill([
                'estado' => 'activo',
                'verificado' => true,
            ]);
            if (! empty($data['password'])) {
                $u->contrasena_hash = Hash::make($data['password']);
                $u->debe_cambiar_contrasena = true;
            }
            $u->save();
            if ($col->estado === 'inactivo') {
                $col->update(['estado' => 'activo']);
            }
        } else {
            $u->forceFill([
                'estado' => 'inactivo',
                'debe_cambiar_contrasena' => false,
            ])->save();
            $col->update(['estado' => 'inactivo']);
        }

        return $this->ok([
            'colaborador' => $col->fresh()->load('usuario'),
            'acceso_habilitado' => $u->fresh()->estado !== 'inactivo',
        ], 'Acceso actualizado');
    }

    public function updatePermisos(Request $request, int $colaboradorId): JsonResponse
    {
        $e = $request->user()->empresaConsultoraTitular;
        if (! $e) {
            return $this->fail('Sin consultora asociada.', 403);
        }

        $col = Colaborador::query()->where('consultora_id', $e->id)->whereKey($colaboradorId)->first();
        if (! $col) {
            return $this->fail('Colaborador no encontrado', 404);
        }

        $payload = $request->validate([
            'puede_editar_empresa_cliente' => ['sometimes', 'boolean'],
            'puede_declarar_aguinaldo' => ['sometimes', 'boolean'],
            'permisos' => ['required', 'array'],
            'permisos.*.modulo' => ['required', 'string', Rule::in(['afp', 'caja', 'ministerio'])],
            'permisos.*.puede_ver' => ['sometimes', 'boolean'],
            'permisos.*.puede_registrar_personal' => ['sometimes', 'boolean'],
            'permisos.*.puede_editar_personal' => ['sometimes', 'boolean'],
            'permisos.*.puede_subir_documentos' => ['sometimes', 'boolean'],
            'permisos.*.puede_eliminar_documentos' => ['sometimes', 'boolean'],
            'permisos.*.puede_gestionar_modulo' => ['sometimes', 'boolean'],
            'permisos.*.puede_exportar_reportes' => ['sometimes', 'boolean'],
            'permisos.*.puede_invitar_empresa' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('puede_editar_empresa_cliente', $payload)) {
            $col->puede_editar_empresa_cliente = $payload['puede_editar_empresa_cliente'];
            $col->save();
        }

        if (array_key_exists('puede_declarar_aguinaldo', $payload)) {
            $col->puede_declarar_aguinaldo = $request->boolean('puede_declarar_aguinaldo');
            $col->save();
        }

        foreach ($payload['permisos'] as $row) {
            ColaboradorPermiso::query()->updateOrCreate(
                ['colaborador_id' => $col->id, 'modulo' => $row['modulo']],
                [
                    'puede_ver' => $row['puede_ver'] ?? false,
                    'puede_registrar_personal' => $row['puede_registrar_personal'] ?? false,
                    'puede_editar_personal' => $row['puede_editar_personal'] ?? false,
                    'puede_subir_documentos' => $row['puede_subir_documentos'] ?? false,
                    'puede_eliminar_documentos' => $row['puede_eliminar_documentos'] ?? false,
                    'puede_gestionar_modulo' => $row['puede_gestionar_modulo'] ?? false,
                    'puede_exportar_reportes' => $row['puede_exportar_reportes'] ?? false,
                    'puede_invitar_empresa' => $row['puede_invitar_empresa'] ?? false,
                    'configurado_por' => $e->id,
                ]
            );
        }

        $colFresh = $col->fresh();

        return $this->ok([
            'puede_editar_empresa_cliente' => (bool) $colFresh->puede_editar_empresa_cliente,
            'puede_declarar_aguinaldo' => (bool) $colFresh->puede_declarar_aguinaldo,
            'permisos_por_modulo' => $colFresh->permisosPorModulo()->get()->map(static function ($p) {
                return [
                    'modulo' => $p->modulo,
                    'puede_ver' => (bool) $p->puede_ver,
                    'puede_registrar_personal' => (bool) $p->puede_registrar_personal,
                    'puede_editar_personal' => (bool) $p->puede_editar_personal,
                    'puede_subir_documentos' => (bool) $p->puede_subir_documentos,
                    'puede_eliminar_documentos' => (bool) $p->puede_eliminar_documentos,
                    'puede_gestionar_modulo' => (bool) $p->puede_gestionar_modulo,
                    'puede_exportar_reportes' => (bool) $p->puede_exportar_reportes,
                    'puede_invitar_empresa' => (bool) $p->puede_invitar_empresa,
                ];
            })->values()->all(),
        ]);
    }

    private function seedPermisos(Colaborador $col, string $cargo, int $consultoraId): void
    {
        $modulos = ['afp', 'caja', 'ministerio'];
        $allOn = fn () => [
            'puede_ver' => true,
            'puede_registrar_personal' => true,
            'puede_editar_personal' => true,
            'puede_subir_documentos' => true,
            'puede_eliminar_documentos' => true,
            'puede_gestionar_modulo' => true,
            'puede_exportar_reportes' => true,
            'puede_invitar_empresa' => $cargo === 'coordinador_general',
        ];

        $asistente = fn () => [
            'puede_ver' => true,
            'puede_registrar_personal' => false,
            'puede_editar_personal' => false,
            'puede_subir_documentos' => true,
            'puede_eliminar_documentos' => false,
            'puede_gestionar_modulo' => false,
            'puede_exportar_reportes' => false,
            'puede_invitar_empresa' => false,
        ];

        foreach ($modulos as $m) {
            $base = match ($cargo) {
                'coordinador_general' => $allOn(),
                'asistente' => $asistente(),
                default => [
                    'puede_ver' => true,
                    'puede_registrar_personal' => false,
                    'puede_editar_personal' => false,
                    'puede_subir_documentos' => true,
                    'puede_eliminar_documentos' => false,
                    'puede_gestionar_modulo' => false,
                    'puede_exportar_reportes' => false,
                    'puede_invitar_empresa' => false,
                ],
            };

            if (str_starts_with($cargo, 'analista_')) {
                $mod = str_replace('analista_', '', $cargo);
                $base['puede_gestionar_modulo'] = $mod === $m;
                $base['puede_subir_documentos'] = $mod === $m;
            }

            ColaboradorPermiso::create(array_merge([
                'colaborador_id' => $col->id,
                'modulo' => $m,
                'configurado_por' => $consultoraId,
            ], $base));
        }

        $tieneDeclaracionMensual = ColaboradorPermiso::query()
            ->where('colaborador_id', $col->id)
            ->where('puede_gestionar_modulo', true)
            ->exists();
        $col->update(['puede_declarar_aguinaldo' => $tieneDeclaracionMensual]);
    }
}
