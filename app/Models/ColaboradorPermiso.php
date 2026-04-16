<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ColaboradorPermiso extends Model
{
    protected $table = 'colaborador_permisos';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'colaborador_id',
        'modulo',
        'puede_ver',
        'puede_registrar_personal',
        'puede_editar_personal',
        'puede_subir_documentos',
        'puede_eliminar_documentos',
        'puede_gestionar_modulo',
        'puede_exportar_reportes',
        'puede_invitar_empresa',
        'configurado_por',
    ];

    protected function casts(): array
    {
        return [
            'puede_ver' => 'boolean',
            'puede_registrar_personal' => 'boolean',
            'puede_editar_personal' => 'boolean',
            'puede_subir_documentos' => 'boolean',
            'puede_eliminar_documentos' => 'boolean',
            'puede_gestionar_modulo' => 'boolean',
            'puede_exportar_reportes' => 'boolean',
            'puede_invitar_empresa' => 'boolean',
            'actualizado_en' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }
}
