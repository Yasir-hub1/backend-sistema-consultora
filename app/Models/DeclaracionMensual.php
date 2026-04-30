<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclaracionMensual extends Model
{
    protected $table = 'declaraciones_mensuales';

    protected $fillable = [
        'empresa_cliente_id',
        'anio',
        'mes',
        'modulo',
        'monto_total_ganado',
        'monto_deposito_cns',
        'monto_aportes_gestoras',
        'monto_aporte_solidario_gestora',
        'monto_planilla_mensual_mdt',
        'monto_seprec_registro_poder_consultora',
        'nombre_archivo',
        'nombre_original',
        'ruta_archivo',
        'formato',
        'tamano_bytes',
        'subido_por',
        'fecha_subida',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'monto_total_ganado' => 'decimal:2',
            'monto_deposito_cns' => 'decimal:2',
            'monto_aportes_gestoras' => 'decimal:2',
            'monto_aporte_solidario_gestora' => 'decimal:2',
            'monto_planilla_mensual_mdt' => 'decimal:2',
            'monto_seprec_registro_poder_consultora' => 'decimal:2',
            'tamano_bytes' => 'integer',
            'fecha_subida' => 'datetime',
        ];
    }

    public function empresaCliente(): BelongsTo
    {
        return $this->belongsTo(EmpresaCliente::class, 'empresa_cliente_id');
    }

    public function subidoPorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'subido_por');
    }
}
