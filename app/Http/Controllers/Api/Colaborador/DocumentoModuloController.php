<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\Documento;
use App\Models\EmpresaCliente;
use App\Models\Personal;
use App\Models\TipoDocumento;
use App\Services\CumplimientoModuloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentoModuloController extends ApiController
{
    public function __construct(
        private CumplimientoModuloService $cumplimiento
    ) {}

    private function consultoraIdDelUsuario(Request $request): ?int
    {
        $u = $request->user();
        if ($u->tipo === 'consultora') {
            return $u->empresaConsultoraTitular?->id;
        }
        if ($u->tipo === 'colaborador') {
            return $u->colaborador?->consultora_id;
        }

        return null;
    }

    private function puede(Request $request, int $empresaId): bool
    {
        $empresa = EmpresaCliente::query()->find($empresaId);
        if (! $empresa) {
            return false;
        }
        $u = $request->user();
        if ($u->tipo === 'consultora' && ($ec = $u->empresaConsultoraTitular)) {
            return $ec->id === $empresa->consultora_id;
        }
        $c = $u->colaborador;

        return $c && $c->empresasCliente()->whereKey($empresaId)->wherePivot('activo', true)->exists();
    }

    public function index(Request $request, int $empresaClienteId, int $personalId, string $modulo): JsonResponse
    {
        if (! in_array($modulo, ['afp', 'caja', 'ministerio'], true)) {
            return $this->fail('Módulo inválido', 422);
        }

        if (! $this->puede($request, $empresaClienteId)) {
            return $this->fail('Sin acceso', 403);
        }

        $per = Personal::query()->where('empresa_id', $empresaClienteId)->find($personalId);
        if (! $per) {
            return $this->fail('Personal no encontrado', 404);
        }

        $docs = Documento::query()
            ->where('personal_id', $per->id)
            ->where('modulo', $modulo)
            ->where('eliminado', false)
            ->with('tipoDocumento')
            ->orderByDesc('fecha_subida')
            ->get();

        return $this->ok([
            'documentos' => $docs,
            'items' => $docs,
        ]);
    }

    public function tipos(Request $request, string $modulo): JsonResponse
    {
        if (! in_array($modulo, ['afp', 'caja', 'ministerio'], true)) {
            return $this->fail('Módulo inválido', 422);
        }

        $consultoraId = $this->consultoraIdDelUsuario($request);

        $q = TipoDocumento::query()
            ->where('modulo', $modulo)
            ->where('activo', true)
            ->visiblesParaConsultora($consultoraId);

        if ($modulo === 'caja') {
            $v = $request->query('caja_variante');
            if (in_array($v, ['nacional', 'petrolera'], true)) {
                $q->where('caja_variante', $v);
            }
        }

        $tipos = $q->orderBy('orden_visualizacion')->orderBy('id')->get();

        return $this->ok($tipos);
    }

    public function store(Request $request, int $empresaClienteId, int $personalId, string $modulo): JsonResponse
    {
        if (! in_array($modulo, ['afp', 'caja', 'ministerio'], true)) {
            return $this->fail('Módulo inválido', 422);
        }

        if (! $this->puede($request, $empresaClienteId)) {
            return $this->fail('Sin acceso', 403);
        }

        $per = Personal::query()->where('empresa_id', $empresaClienteId)->find($personalId);
        if (! $per) {
            return $this->fail('Personal no encontrado', 404);
        }

        $colab = $request->user()->colaborador;

        $request->validate([
            'archivo' => ['required', 'file', 'max:10240'],
            'tipo_documento_id' => ['required', 'integer', 'exists:tipos_documento,id'],
            'periodo' => ['nullable', 'string', 'max:20'],
            'observacion' => ['nullable', 'string'],
        ]);

        $tipo = TipoDocumento::query()->findOrFail($request->integer('tipo_documento_id'));
        if ($tipo->modulo !== $modulo) {
            return $this->fail('El tipo no pertenece al módulo.', 422);
        }

        $per->loadMissing('empresaCliente');
        $consultoraId = $per->empresaCliente?->consultora_id;
        $tipoVisible = TipoDocumento::query()
            ->whereKey($tipo->id)
            ->where('modulo', $modulo)
            ->where('activo', true)
            ->visiblesParaConsultora($consultoraId)
            ->exists();
        if (! $tipoVisible) {
            return $this->fail('Este tipo de documento no está disponible para tu consultora.', 422);
        }

        $file = $request->file('archivo');
        $ext = strtolower($file->getClientOriginalExtension());
        $consultoraId = $per->empresaCliente->consultora_id;
        $dir = "docs/consultora_{$consultoraId}/empresa_{$empresaClienteId}/personal_{$personalId}/{$modulo}";
        $stored = $file->store($dir, 'local');

        Documento::query()
            ->where('personal_id', $per->id)
            ->where('tipo_documento_id', $tipo->id)
            ->where('es_vigente', true)
            ->update(['es_vigente' => false]);

        $doc = Documento::create([
            'personal_id' => $per->id,
            'tipo_documento_id' => $tipo->id,
            'modulo' => $modulo,
            'nombre_archivo' => basename($stored),
            'nombre_original' => $file->getClientOriginalName(),
            'ruta_archivo' => $stored,
            'formato' => $ext,
            'tamano_bytes' => $file->getSize(),
            'periodo' => $request->input('periodo'),
            'observacion' => $request->input('observacion'),
            'es_vigente' => true,
            'subido_por' => $colab?->id,
            'fecha_subida' => now(),
        ]);

        $this->cumplimiento->recalcularPersonal($per->fresh(), $modulo);

        return $this->ok($doc->load('tipoDocumento'), 'Documento guardado', 201);
    }
}
