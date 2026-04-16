<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\EmpresaCliente;
use App\Models\Personal;
use App\Services\PersonalRegistroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalController extends ApiController
{
    public function __construct(
        private PersonalRegistroService $personalRegistroService
    ) {}

    private function empresaAccesible(Request $request, int $empresaId): ?EmpresaCliente
    {
        $empresa = EmpresaCliente::query()->find($empresaId);
        if (! $empresa) {
            return null;
        }

        $u = $request->user();
        if ($u->tipo === 'consultora' && ($ec = $u->empresaConsultoraTitular)) {
            return $ec->id === $empresa->consultora_id ? $empresa : null;
        }

        $c = $u->colaborador;
        if (! $c) {
            return null;
        }

        return $c->empresasCliente()->whereKey($empresaId)->wherePivot('activo', true)->first();
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
                $like = '%'.$s.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('nombres', 'like', $like)
                        ->orWhere('apellidos', 'like', $like)
                        ->orWhere('ci', 'like', $like);
                });
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

        return $this->ok($per);
    }

    public function store(Request $request, int $empresaClienteId): JsonResponse
    {
        if (! $this->empresaAccesible($request, $empresaClienteId)) {
            return $this->fail('Sin acceso a esta empresa.', 403);
        }

        $data = $request->validate([
            'nombres' => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'ci' => ['required', 'string', 'max:20'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'cargo' => ['required', 'string', 'max:150'],
            'fecha_ingreso' => ['required', 'date'],
            'afp_id' => ['nullable'],
            'nro_afp' => ['nullable', 'string', 'max:50'],
            'caja_id' => ['nullable'],
            'nro_caja' => ['nullable', 'string', 'max:50'],
        ]);

        if (Personal::query()->where('empresa_id', $empresaClienteId)->where('ci', $data['ci'])->exists()) {
            return $this->fail('CI ya registrado en esta empresa.', 422);
        }

        $colabId = $request->user()->colaborador?->id;

        $per = Personal::create([
            'empresa_id' => $empresaClienteId,
            'registrado_por' => $colabId,
            'nombres' => $data['nombres'],
            'apellidos' => $data['apellidos'],
            'ci' => $data['ci'],
            'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
            'cargo' => $data['cargo'],
            'fecha_ingreso' => $data['fecha_ingreso'],
        ]);

        $this->personalRegistroService->crearConModulos($per, [
            'numero_afiliado' => $data['nro_afp'] ?? null,
        ], [
            'numero_asegurado' => $data['nro_caja'] ?? null,
        ]);

        return $this->ok($per->load(['afp', 'caja', 'ministerio']), 'Personal creado', 201);
    }
}
