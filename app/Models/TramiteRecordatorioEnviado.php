<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TramiteRecordatorioEnviado extends Model
{
    public const TIPOS = [
        'vencimiento_3d',
        'vencimiento_1d',
        'vencimiento_hoy',
    ];

    protected $table = 'tramite_recordatorios_enviados';

    public $timestamps = false;

    protected $fillable = [
        'tramite_id',
        'periodo',
        'tipo',
        'alerta_ids',
        'reintentos',
        'enviado_en',
    ];

    protected function casts(): array
    {
        return [
            'alerta_ids' => 'array',
            'enviado_en' => 'datetime',
        ];
    }

    public function tramite(): BelongsTo
    {
        return $this->belongsTo(Tramite::class, 'tramite_id');
    }
}
