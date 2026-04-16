<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuración operativa 1:1 de la consultora (marca, bancos, plantilla de entrega).
 */
class ConfiguracionConsultora extends Model
{
    protected $table = 'configuracion_consultora';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'consultora_id',
        'logo_url',
        'color_marca',
        'correo_soporte',
        'telefono_soporte',
        'institucion_financiera_id',
        'banco',
        'nro_cuenta',
        'tipo_cuenta',
        'titular_cuenta',
        'moneda',
        'banco_alt',
        'nro_cuenta_alt',
        'tipo_cuenta_alt',
        'titular_cuenta_alt',
        'moneda_alt',
        'plantilla_entrega',
        'notif_correo_alertas',
        'notif_dias_anticipacion',
    ];

    protected function casts(): array
    {
        return [
            'plantilla_entrega' => 'array',
            'notif_correo_alertas' => 'boolean',
            'actualizado_en' => 'datetime',
        ];
    }

    public function consultora(): BelongsTo
    {
        return $this->belongsTo(EmpresaConsultora::class, 'consultora_id');
    }

    public function institucionFinanciera(): BelongsTo
    {
        return $this->belongsTo(InstitucionFinanciera::class, 'institucion_financiera_id');
    }
}
