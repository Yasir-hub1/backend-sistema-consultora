<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstitucionFinanciera extends Model
{
    protected $table = 'instituciones_financieras';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'tipo',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function configuracionesConsultora(): HasMany
    {
        return $this->hasMany(ConfiguracionConsultora::class, 'institucion_financiera_id');
    }
}
