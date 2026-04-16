<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta o pendiente vinculada a la consultora.
 */
class Alerta extends Model
{
    protected $table = 'alertas';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'consultora_id',
        'empresa_id',
        'personal_id',
        'colaborador_asignado',
        'modulo',
        'nivel',
        'titulo',
        'descripcion',
        'fecha_vencimiento',
        'resuelta',
        'resuelta_por',
        'resuelta_en',
        'generada_auto',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'resuelta' => 'boolean',
            'resuelta_en' => 'datetime',
            'generada_auto' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    public function consultora(): BelongsTo
    {
        return $this->belongsTo(EmpresaConsultora::class, 'consultora_id');
    }

    public function empresaCliente(): BelongsTo
    {
        return $this->belongsTo(EmpresaCliente::class, 'empresa_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function colaboradorAsignado(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_asignado');
    }

    public function resueltaPorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'resuelta_por');
    }
}
