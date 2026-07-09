<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tarea extends Model
{
    public const ESTADOS = ['pendiente', 'en_proceso', 'completada'];

    protected $table = 'tareas';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'tramite_id',
        'nombre',
        'descripcion',
        'estado',
        'responsable_id',
        'orden',
        'requiere_documento',
        'completada_en',
    ];

    protected function casts(): array
    {
        return [
            'requiere_documento' => 'boolean',
            'completada_en' => 'datetime',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class, 'tramite_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'responsable_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(TareaDocumento::class, 'tarea_id');
    }
}
