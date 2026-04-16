<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empresa cliente gestionada por una consultora.
 */
class EmpresaCliente extends Model
{
    protected $table = 'empresas_cliente';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'consultora_id',
        'usuario_id',
        'registrada_por',
        'nombre',
        'nit',
        'razon_social',
        'ciudad',
        'departamento',
        'direccion',
        'telefono',
        'correo_empresa',
        'actividad_economica',
        'matricula_comercio',
        'rep_legal_nombres',
        'rep_legal_apellidos',
        'rep_legal_ci',
        'rep_legal_ext_ci',
        'estado',
        'fecha_registro',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_registro' => 'date',
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

    public function registradaPorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'registrada_por');
    }

    public function colaboradores(): BelongsToMany
    {
        return $this->belongsToMany(Colaborador::class, 'colaborador_empresa_cliente', 'empresa_id', 'colaborador_id')
            ->withPivot(['id', 'activo', 'asignado_por', 'asignado_en', 'removido_en']);
    }

    public function personal(): HasMany
    {
        return $this->hasMany(Personal::class, 'empresa_id');
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class, 'empresa_id');
    }
}
