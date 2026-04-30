<?php

namespace App\Http\Controllers\Api\EmpresaCliente;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $emp = $request->user()->empresaClienteComoUsuario;
        if (! $emp) {
            return $this->fail('Sin empresa asociada.', 403);
        }

        $emp->load(['consultora.configuracion']);
        $personal = $emp->personal()->with(['afp', 'caja', 'ministerio'])->get();
        $total = $personal->count();
        $recordatorios = [
            'afp' => ['faltantes' => 0, 'items' => []],
            'caja' => ['faltantes' => 0, 'items' => []],
            'ministerio' => ['faltantes' => 0, 'items' => []],
        ];

        $pct = function (callable $estadoFn) use ($personal, $total): int {
            if ($total === 0) {
                return 0;
            }
            $ok = $personal->filter($estadoFn)->count();

            return (int) round(100 * $ok / $total);
        };

        foreach ($personal as $per) {
            $nombrePersonal = trim(($per->nombres ?? '').' '.($per->apellidos ?? ''));
            foreach (['afp', 'caja', 'ministerio'] as $modulo) {
                $estado = strtolower((string) optional($per->{$modulo})->estado);
                $alDia = in_array($estado, ['al_dia', 'completo', 'vigente', 'ok', 'activo'], true);
                if ($alDia) {
                    continue;
                }
                $recordatorios[$modulo]['faltantes']++;
                if (count($recordatorios[$modulo]['items']) < 5) {
                    $recordatorios[$modulo]['items'][] = [
                        'personal_id' => $per->id,
                        'personal_nombre' => $nombrePersonal !== '' ? $nombrePersonal : ('Personal #'.$per->id),
                        'estado' => $estado !== '' ? $estado : 'sin_datos',
                        'path' => "/empresa-cliente/personal/{$per->id}",
                    ];
                }
            }
        }

        return $this->ok([
            'empresa' => $emp->only(['id', 'nombre', 'nit', 'razon_social']),
            'consultora' => $emp->consultora?->only(['id', 'razon_social', 'nombre_comercial']),
            'cobertura_afp' => $pct(fn ($p) => $p->afp && $p->afp->estado === 'al_dia'),
            'cobertura_caja' => $pct(fn ($p) => $p->caja && $p->caja->estado === 'al_dia'),
            'cobertura_ministerio' => $pct(fn ($p) => $p->ministerio && $p->ministerio->estado === 'al_dia'),
            'afp' => $total ? $pct(fn ($p) => $p->afp && $p->afp->estado === 'al_dia') : 0,
            'caja' => $total ? $pct(fn ($p) => $p->caja && $p->caja->estado === 'al_dia') : 0,
            'ministerio' => $total ? $pct(fn ($p) => $p->ministerio && $p->ministerio->estado === 'al_dia') : 0,
            'recordatorios_documentos' => $recordatorios,
        ]);
    }
}
