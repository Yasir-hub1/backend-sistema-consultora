<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión de acceso (token) del dominio LaboraConsult.
 *
 * No confundir con la tabla "sessions" que usa el driver de sesión de Laravel.
 */
class Sesion extends Model
{
    protected $table = 'sesiones';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'token_hash',
        'ip_origen',
        'agente_usuario',
        'activa',
        'creado_en',
        'expira_en',
        'cerrado_en',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'creado_en' => 'datetime',
            'expira_en' => 'datetime',
            'cerrado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
