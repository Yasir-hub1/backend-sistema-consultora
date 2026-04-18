<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\Documento;
use App\Models\EmpresaConsultora;
use App\Models\TipoDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TiposDocumentoController extends ApiController
{
    private function empresaConsultora(Request $request): ?EmpresaConsultora
    {
        return $request->user()->empresaConsultoraTitular;
    }

    public function index(Request $request): JsonResponse
    {
        $ec = $this->empresaConsultora($request);
        if (! $ec) {
            return $this->fail('Consultora no encontrada.', 403);
        }

        $modulo = (string) $request->query('modulo', '');
        if (! in_array($modulo, ['afp', 'caja', 'ministerio'], true)) {
            return $this->fail('Parámetro modulo inválido.', 422);
        }

        $q = TipoDocumento::query()
            ->where('modulo', $modulo)
            ->visiblesParaConsultora($ec->id);

        if ($modulo === 'caja') {
            $v = $request->query('caja_variante');
            if (in_array($v, ['nacional', 'petrolera'], true)) {
                $q->where('caja_variante', $v);
            }
        }

        $rows = $q->orderBy('orden_visualizacion')->orderBy('id')->get()->map(function (TipoDocumento $t) {
            $a = $t->toArray();
            $a['es_sistema'] = $t->consultora_id === null;
            $a['editable'] = $t->consultora_id !== null;

            return $a;
        });

        return $this->ok($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $ec = $this->empresaConsultora($request);
        if (! $ec) {
            return $this->fail('Consultora no encontrada.', 403);
        }

        $data = $request->validate([
            'modulo' => ['required', 'string', 'in:afp,caja,ministerio'],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'obligatorio' => ['sometimes', 'boolean'],
            'es_periodico' => ['sometimes', 'boolean'],
            'caja_variante' => ['nullable', 'string', 'in:nacional,petrolera'],
            'formatos_permitidos' => ['nullable', 'string', 'max:100'],
            'tamano_maximo_mb' => ['nullable', 'integer', 'min:1', 'max:50'],
            'orden_visualizacion' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ]);

        if ($data['modulo'] === 'caja' && empty($data['caja_variante'])) {
            return $this->fail('Para módulo CAJA indique nacional o petrolera.', 422);
        }
        if ($data['modulo'] !== 'caja') {
            $data['caja_variante'] = null;
        }

        $maxOrden = (int) TipoDocumento::query()
            ->where('modulo', $data['modulo'])
            ->visiblesParaConsultora($ec->id)
            ->max('orden_visualizacion');

        $tipo = TipoDocumento::query()->create([
            'consultora_id' => $ec->id,
            'modulo' => $data['modulo'],
            'caja_variante' => $data['caja_variante'] ?? null,
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'obligatorio' => $request->boolean('obligatorio', true),
            'es_periodico' => $request->boolean('es_periodico', false),
            'formatos_permitidos' => $data['formatos_permitidos'] ?? 'pdf,xlsx,docx,jpg,png',
            'tamano_maximo_mb' => $data['tamano_maximo_mb'] ?? 10,
            'activo' => true,
            'orden_visualizacion' => $data['orden_visualizacion'] ?? ($maxOrden + 1),
        ]);

        $arr = $tipo->toArray();
        $arr['es_sistema'] = false;
        $arr['editable'] = true;

        return $this->ok($arr, 'Tipo registrado', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $ec = $this->empresaConsultora($request);
        if (! $ec) {
            return $this->fail('Consultora no encontrada.', 403);
        }

        $tipo = TipoDocumento::query()
            ->where('consultora_id', $ec->id)
            ->find($id);

        if (! $tipo) {
            return $this->fail('Tipo no encontrado o es de solo lectura (sistema).', 404);
        }

        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'obligatorio' => ['sometimes', 'boolean'],
            'es_periodico' => ['sometimes', 'boolean'],
            'formatos_permitidos' => ['nullable', 'string', 'max:100'],
            'tamano_maximo_mb' => ['nullable', 'integer', 'min:1', 'max:50'],
            'orden_visualizacion' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $tipo->fill($data);
        $tipo->save();

        $arr = $tipo->fresh()->toArray();
        $arr['es_sistema'] = false;
        $arr['editable'] = true;

        return $this->ok($arr, 'Tipo actualizado');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $ec = $this->empresaConsultora($request);
        if (! $ec) {
            return $this->fail('Consultora no encontrada.', 403);
        }

        $tipo = TipoDocumento::query()
            ->where('consultora_id', $ec->id)
            ->find($id);

        if (! $tipo) {
            return $this->fail('Tipo no encontrado o es de solo lectura (sistema).', 404);
        }

        if (Documento::query()->where('tipo_documento_id', $tipo->id)->exists()) {
            $tipo->activo = false;
            $tipo->save();

            return $this->ok(['id' => $tipo->id, 'activo' => false], 'Tipo desactivado (hay documentos asociados).');
        }

        $tipo->delete();

        return $this->ok(null, 'Tipo eliminado');
    }
}
