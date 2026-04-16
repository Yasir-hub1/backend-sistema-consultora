<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Empresa consultora (firma): unidad central que gestiona clientes y equipo.
 */
class EmpresaConsultora extends Model
{
    protected $table = 'empresas_consultoras';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'usuario_id',
        'registrada_por',
        'razon_social',
        'nombre_comercial',
        'nit',
        'representante_nombres',
        'representante_apellidos',
        'representante_ci',
        'representante_ext_ci',
        'correo_principal',
        'telefono',
        'ciudad',
        'departamento',
        'direccion',
        'estado',
        'configuracion_completa',
        'fecha_registro',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'configuracion_completa' => 'boolean',
            'fecha_registro' => 'date',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function administradorRegistrador(): BelongsTo
    {
        return $this->belongsTo(Administrador::class, 'registrada_por');
    }

    public function configuracion(): HasOne
    {
        return $this->hasOne(ConfiguracionConsultora::class, 'consultora_id');
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'consultora_id');
    }

    public function empresasCliente(): HasMany
    {
        return $this->hasMany(EmpresaCliente::class, 'consultora_id');
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class, 'consultora_id');
    }

    public function actividadLogs(): HasMany
    {
        return $this->hasMany(ActividadLog::class, 'consultora_id');
    }
}
