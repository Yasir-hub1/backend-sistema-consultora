<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'numero_cua',
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
        'curriculum_archivo_path',
        'curriculum_archivo_nombre',
        'licencia_conducir_archivo_path',
        'licencia_conducir_archivo_nombre',
        'aviso_luz_agua_archivo_path',
        'aviso_luz_agua_archivo_nombre',
        'croquis_archivo_path',
        'croquis_archivo_nombre',
        'certificado_nacimiento_archivo_path',
        'certificado_nacimiento_archivo_nombre',
        'contactos_referencia',
        'correo_electronico',
        'cuenta_bancaria',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_ingreso' => 'date',
            'fecha_egreso' => 'date',
            'salario_mensual' => 'decimal:2',
            'contactos_referencia' => 'array',
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

    public function gestoraPeriodos(): HasMany
    {
        return $this->hasMany(PersonalGestoraPeriodo::class, 'personal_id');
    }

    public function scopeBusqueda(Builder $query, string $texto): Builder
    {
        $like = '%'.$texto.'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('nombres', 'like', $like)
                ->orWhere('apellidos', 'like', $like)
                ->orWhere('ci', 'like', $like)
                ->orWhere('numero_cua', 'like', $like);
        });
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
