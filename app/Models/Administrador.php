<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil extendido de un usuario administrador.
 */
class Administrador extends Model
{
    protected $table = 'administradores';

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'nombres',
        'apellidos',
        'telefono',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'creado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** Empresas consultoras registradas por este administrador. */
    public function empresasConsultorasRegistradas(): HasMany
    {
        return $this->hasMany(EmpresaConsultora::class, 'registrada_por');
    }
}
