<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada de auditoría (acción sobre una entidad).
 */
class ActividadLog extends Model
{
    protected $table = 'actividad_log';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'consultora_id',
        'accion',
        'modulo',
        'entidad',
        'entidad_id',
        'descripcion',
        'datos_anteriores',
        'datos_nuevos',
        'ip_origen',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'datos_anteriores' => 'array',
            'datos_nuevos' => 'array',
            'creado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function consultora(): BelongsTo
    {
        return $this->belongsTo(EmpresaConsultora::class, 'consultora_id');
    }
}
