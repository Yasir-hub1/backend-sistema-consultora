<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalCaja extends Model
{
    protected $table = 'personal_caja';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'personal_id',
        'caja_nombre',
        'numero_asegurado',
        'fecha_afiliacion',
        'estado',
        'observaciones',
        'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_afiliacion' => 'date',
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
