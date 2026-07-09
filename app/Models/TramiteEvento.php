<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramiteEvento extends Model
{
    protected $table = 'tramite_eventos';

    public $timestamps = false;

    protected $fillable = [
        'tramite_id',
        'tipo',
        'titulo',
        'descripcion',
        'metadata',
        'usuario_id',
        'ocurrido_en',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'ocurrido_en' => 'datetime',
        ];
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class, 'tramite_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
