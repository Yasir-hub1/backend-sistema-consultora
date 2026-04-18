<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'consultora_id',
        'modulo',
        'caja_variante',
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

    public function consultora(): BelongsTo
    {
        return $this->belongsTo(EmpresaConsultora::class, 'consultora_id');
    }

    /**
     * Tipos de plantilla global (consultora_id null) más los definidos por la firma.
     */
    public function scopeVisiblesParaConsultora(Builder $query, ?int $consultoraId): Builder
    {
        return $query->where(function (Builder $q) use ($consultoraId) {
            $q->whereNull('consultora_id');
            if ($consultoraId !== null && $consultoraId > 0) {
                $q->orWhere('consultora_id', $consultoraId);
            }
        });
    }
}
