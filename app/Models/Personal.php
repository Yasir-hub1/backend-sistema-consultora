<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Empleado de una empresa cliente.
 */
class Personal extends Model
{
    protected $table = 'personal';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'empresa_id',
        'registrado_por',
        'nombres',
        'apellidos',
        'ci',
        'extension_ci',
        'fecha_nacimiento',
        'genero',
        'estado_civil',
        'telefono',
        'correo',
        'direccion',
        'nivel_educacion',
        'profesion',
        'cargo',
        'fecha_ingreso',
        'fecha_egreso',
        'tipo_contrato',
        'salario_mensual',
        'modalidad',
        'estado',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_ingreso' => 'date',
            'fecha_egreso' => 'date',
            'salario_mensual' => 'decimal:2',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function empresaCliente(): BelongsTo
    {
        return $this->belongsTo(EmpresaCliente::class, 'empresa_id');
    }

    public function registradoPorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'registrado_por');
    }

    public function afp(): HasOne
    {
        return $this->hasOne(PersonalAfp::class, 'personal_id');
    }

    public function caja(): HasOne
    {
        return $this->hasOne(PersonalCaja::class, 'personal_id');
    }

    public function ministerio(): HasOne
    {
        return $this->hasOne(PersonalMinisterio::class, 'personal_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class, 'personal_id');
    }

    public function alertas(): HasMany
    {
        return $this->hasMany(Alerta::class, 'personal_id');
    }
}
