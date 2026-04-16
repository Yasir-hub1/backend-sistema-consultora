<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de documento del catálogo por módulo.
 */
class TipoDocumento extends Model
{
    protected $table = 'tipos_documento';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = null;

    protected $fillable = [
        'modulo',
        'nombre',
        'descripcion',
        'obligatorio',
        'es_periodico',
        'formatos_permitidos',
        'tamano_maximo_mb',
        'activo',
        'orden_visualizacion',
        'creado_en',
    ];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
            'es_periodico' => 'boolean',
            'activo' => 'boolean',
            'creado_en' => 'datetime',
        ];
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class, 'tipo_documento_id');
    }
}
