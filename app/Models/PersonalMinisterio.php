<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalMinisterio extends Model
{
    protected $table = 'personal_ministerio';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'personal_id',
        'numero_registro_mt',
        'fecha_registro',
        'tipo_contrato_mt',
        'estado',
        'observaciones',
        'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'date',
            'actualizado_en' => 'datetime',
        ];
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function actualizadoPorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'actualizado_por');
    }
}
