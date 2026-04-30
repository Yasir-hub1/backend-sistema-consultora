<?php

namespace App\Http\Controllers\Api\Colaborador;

use App\Http\Controllers\Api\ApiController;
use App\Models\Alerta;
use App\Models\Personal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $u = $request->user();
        $empresas = 0;
        $alertas = 0;
        $recordatorios = [
            'afp' => ['faltantes' => 0, 'items' => []],
            'caja' => ['faltantes' => 0, 'items' => []],
            'ministerio' => ['faltantes' => 0, 'items' => []],
        ];

        if ($u->tipo === 'colaborador' && ($c = $u->colaborador)) {
            $empresas = $c->empresasCliente()->wherePivot('activo', true)->count();
            $alertas = Alerta::query()
                ->where('colaborador_asignado', $c->id)
                ->where('resuelta', false)
                ->where('leida', false)
                ->count();

            $empresaIds = $c->empresasCliente()
                ->wherePivot('activo', true)
                ->pluck('empresas_cliente.id')
                ->all();

            if ($empresaIds !== []) {
                $personal = Personal::query()
                    ->whereIn('empresa_id', $empresaIds)
                    ->with([
                        'empresaCliente:id,nombre,razon_social',
                        'afp:id,personal_id,estado',
                        'caja:id,personal_id,estado',
                        'ministerio:id,personal_id,estado',
                    ])
                    ->get();

                foreach ($personal as $per) {
                    $empresaNombre = $per->empresaCliente?->nombre ?: ($per->empresaCliente?->razon_social ?: 'Empresa');
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
                                'empresa_id' => $per->empresa_id,
                                'personal_nombre' => $nombrePersonal !== '' ? $nombrePersonal : ('Personal #'.$per->id),
                                'empresa_nombre' => $empresaNombre,
                                'estado' => $estado !== '' ? $estado : 'sin_datos',
                                'path' => "/colaborador/empresas/{$per->empresa_id}/personal/{$per->id}",
                            ];
                        }
                    }
                }
            }
        }

        if ($u->tipo === 'consultora' && ($e = $u->empresaConsultoraTitular)) {
            $empresas = $e->empresasCliente()->count();
            $alertas = Alerta::query()
                ->where('consultora_id', $e->id)
                ->where('resuelta', false)
                ->where('leida', false)
                ->count();
        }

        return $this->ok([
            'empresas_asignadas' => $empresas,
            'alertas_pendientes' => $alertas,
            'recordatorios_documentos' => $recordatorios,
        ]);
    }
}
