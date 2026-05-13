<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Miembro del equipo de una empresa consultora.
 */
class Colaborador extends Model
{
    protected $table = 'colaboradores';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'consultora_id',
        'usuario_id',
        'nombres',
        'apellidos',
        'ci',
        'extension_ci',
        'telefono',
        'cargo',
        'fecha_ingreso',
        'estado',
        'observaciones',
        'puede_editar_empresa_cliente',
        'puede_declarar_aguinaldo',
        'puede_gestionar_otros_documentos_empresa',
        'puede_gestionar_documentos_legales_mi_empresa',
    ];

    protected function casts(): array
    {
        return [
            'fecha_ingreso' => 'date',
            'puede_editar_empresa_cliente' => 'boolean',
            'puede_declarar_aguinaldo' => 'boolean',
            'puede_gestionar_otros_documentos_empresa' => 'boolean',
            'puede_gestionar_documentos_legales_mi_empresa' => 'boolean',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function consultora(): BelongsTo
    {
        return $this->belongsTo(EmpresaConsultora::class, 'consultora_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function empresasCliente(): BelongsToMany
    {
        return $this->belongsToMany(EmpresaCliente::class, 'colaborador_empresa_cliente', 'colaborador_id', 'empresa_id')
            ->withPivot(['id', 'activo', 'asignado_por', 'asignado_en', 'removido_en']);
    }

    public function permisosPorModulo(): HasMany
    {
        return $this->hasMany(ColaboradorPermiso::class, 'colaborador_id');
    }

    public function personalRegistrado(): HasMany
    {
        return $this->hasMany(Personal::class, 'registrado_por');
    }

    public function alertasAsignadas(): HasMany
    {
        return $this->hasMany(Alerta::class, 'colaborador_asignado');
    }
}
