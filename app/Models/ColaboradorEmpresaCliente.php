<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación colaborador ↔ empresa cliente.
 */
class ColaboradorEmpresaCliente extends Model
{
    protected $table = 'colaborador_empresa_cliente';

    public $timestamps = false;

    protected $fillable = [
        'colaborador_id',
        'empresa_id',
        'activo',
        'asignado_por',
        'asignado_en',
        'removido_en',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'asignado_en' => 'datetime',
            'removido_en' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function empresaCliente(): BelongsTo
    {
        return $this->belongsTo(EmpresaCliente::class, 'empresa_id');
    }

    public function asignadoPorConsultora(): BelongsTo
    {
        return $this->belongsTo(EmpresaConsultora::class, 'asignado_por');
    }
}
