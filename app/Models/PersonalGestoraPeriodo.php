<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalGestoraPeriodo extends Model
{
    protected $table = 'personal_gestora_periodos';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'personal_id',
        'anio',
        'mes',
        'dias_trabajados',
        'total_ganado',
        'habilitado',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'mes' => 'integer',
            'dias_trabajados' => 'integer',
            'total_ganado' => 'decimal:2',
            'habilitado' => 'boolean',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }
}
