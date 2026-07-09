<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TareaDocumento extends Model
{
    protected $table = 'tarea_documentos';

    public $timestamps = false;

    protected $fillable = [
        'tarea_id',
        'periodo',
        'tipo',
        'nombre_original',
        'ruta_archivo',
        'formato',
        'tamano_bytes',
        'subido_por_usuario_id',
        'fecha_subida',
    ];

    protected function casts(): array
    {
        return [
            'fecha_subida' => 'datetime',
        ];
    }

    public function tarea(): BelongsTo
    {
        return $this->belongsTo(Tarea::class, 'tarea_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'subido_por_usuario_id');
    }
}
