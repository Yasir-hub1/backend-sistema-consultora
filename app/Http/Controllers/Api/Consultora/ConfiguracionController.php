<?php

namespace App\Http\Controllers\Api\Consultora;

use App\Http\Controllers\Api\ApiController;
use App\Models\ConfiguracionConsultora;
use App\Models\EmpresaConsultora;
use App\Models\InstitucionFinanciera;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConfiguracionController extends ApiController
{
    private function empresa(Request $request): ?EmpresaConsultora
    {
        return $request->user()->empresaConsultoraTitular;
    }

    private function configuracionPara(EmpresaConsultora $e): ConfiguracionConsultora
    {
        return $e->configuracion
            ?? ConfiguracionConsultora::query()->firstOrCreate(['consultora_id' => $e->id]);
    }

    public function show(Request $request): JsonResponse
    {
        $e = $this->empresa($request);
        if (! $e) {
            return $this->fail('No eres titular de una consultora.', 403);
        }

        $cfg = $this->configuracionPara($e);
        $cfg->load('institucionFinanciera');

        return $this->ok([
            'configuracion' => $cfg,
            'consultora' => $e,
            'configuracion_completa' => $e->configuracion_completa,
        ]);
    }

    public function guardarPaso(Request $request, int $paso): JsonResponse
    {
        $e = $this->empresa($request);
        if (! $e) {
            return $this->fail('No eres titular de una consultora.', 403);
        }

        if ($paso < 1 || $paso > 3) {
            return $this->fail('Paso inválido', 422);
        }

        $cfg = $this->configuracionPara($e);

        if ($paso === 1) {
            $v = $request->validate([
                'nombre_comercial' => ['nullable', 'string', 'max:150'],
                'color_marca' => ['nullable', 'string', 'max:7'],
                'correo_soporte' => ['nullable', 'email', 'max:150'],
                'telefono_contacto' => ['nullable', 'string', 'max:20'],
                'telefono_soporte' => ['nullable', 'string', 'max:20'],
            ]);
            $cfg->update([
                'color_marca' => $v['color_marca'] ?? $cfg->color_marca,
                'correo_soporte' => $v['correo_soporte'] ?? $cfg->correo_soporte,
                'telefono_soporte' => $v['telefono_soporte'] ?? $v['telefono_contacto'] ?? $cfg->telefono_soporte,
            ]);
            if (! empty($v['nombre_comercial'])) {
                $e->update(['nombre_comercial' => $v['nombre_comercial']]);
            }
        }

        if ($paso === 2) {
            $v = $request->validate([
                'institucion_financiera_id' => ['required', 'integer', 'exists:instituciones_financieras,id'],
                'nro_cuenta' => ['required', 'string', 'max:50'],
                'tipo_cuenta' => ['required', Rule::in(['ahorro', 'corriente'])],
                'titular_cuenta' => ['required', 'string', 'max:200'],
                'moneda' => ['required', Rule::in(['BOB', 'USD'])],
            ]);

            $inst = InstitucionFinanciera::query()->findOrFail($v['institucion_financiera_id']);

            $cfg->update([
                'institucion_financiera_id' => $inst->id,
                'banco' => $inst->nombre,
                'nro_cuenta' => $v['nro_cuenta'],
                'tipo_cuenta' => $v['tipo_cuenta'],
                'titular_cuenta' => $v['titular_cuenta'],
                'moneda' => $v['moneda'],
            ]);
        }

        if ($paso === 3) {
            $v = $request->validate([
                'plantilla_entrega' => ['required', 'array'],
                'plantilla_entrega.campos' => ['required', 'array', 'min:1'],
                'plantilla_entrega.campos.*.id' => ['required', 'string', 'max:64'],
                'plantilla_entrega.campos.*.etiqueta' => ['required', 'string', 'max:200'],
                'plantilla_entrega.campos.*.obligatorio' => ['sometimes', 'boolean'],
            ]);
            $cfg->update(['plantilla_entrega' => $v['plantilla_entrega']]);
        }

        return $this->ok($cfg->fresh()->load('institucionFinanciera'), 'Paso guardado');
    }

    public function subirLogo(Request $request): JsonResponse
    {
        $e = $this->empresa($request);
        if (! $e) {
            return $this->fail('No eres titular de una consultora.', 403);
        }

        $request->validate([
            'logo' => ['required', 'file', 'max:2048', 'mimes:png,svg,jpg,jpeg'],
        ]);

        $cfg = $this->configuracionPara($e);
        $path = $request->file('logo')->store("logos/consultora_{$e->id}", 'public');
        $cfg->update(['logo_url' => '/storage/'.$path]);

        return $this->ok(['logo_url' => $cfg->logo_url]);
    }

    public function finalizar(Request $request): JsonResponse
    {
        $e = $this->empresa($request);
        if (! $e) {
            return $this->fail('No eres titular de una consultora.', 403);
        }

        $cfg = $this->configuracionPara($e)->load('institucionFinanciera');
        $pendientes = $this->pendientesParaActivar($cfg, $e);
        if ($pendientes !== []) {
            return $this->fail('Completa la configuración antes de activar.', 422, [
                'pendientes' => $pendientes,
            ]);
        }

        $e->forceFill([
            'configuracion_completa' => true,
            'estado' => 'activo_operativo',
        ])->save();

        return $this->ok($e->fresh()->load('configuracion.institucionFinanciera'), 'Consultora operativa');
    }

    /**
     * @return list<string>
     */
    private function pendientesParaActivar(ConfiguracionConsultora $cfg, EmpresaConsultora $e): array
    {
        $p = [];

        if (empty(trim((string) $cfg->logo_url))) {
            $p[] = 'Sube el logo de la consultora (paso 1).';
        }
        if (empty(trim((string) $cfg->correo_soporte)) || empty(trim((string) $cfg->telefono_soporte))) {
            $p[] = 'Completa correo y teléfono de soporte (paso 1).';
        }
        if (empty(trim((string) $e->nombre_comercial)) && empty(trim((string) $e->razon_social))) {
            $p[] = 'Indica al menos el nombre comercial o razón social (paso 1).';
        }

        if (empty($cfg->institucion_financiera_id)) {
            $p[] = 'Selecciona la institución financiera (paso 2).';
        }
        foreach (['nro_cuenta', 'titular_cuenta', 'tipo_cuenta', 'moneda'] as $campo) {
            if (empty(trim((string) $cfg->{$campo}))) {
                $p[] = 'Completa todos los datos bancarios (paso 2).';
                break;
            }
        }

        $campos = is_array($cfg->plantilla_entrega) ? ($cfg->plantilla_entrega['campos'] ?? []) : [];
        if (! is_array($campos) || count($campos) < 1) {
            $p[] = 'Define la plantilla de entrega con al menos un campo (paso 3).';
        }

        return array_values(array_unique($p));
    }
}
