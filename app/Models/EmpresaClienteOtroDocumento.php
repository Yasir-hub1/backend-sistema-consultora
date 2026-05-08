<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmpresaClienteOtroDocumento extends Model
{
    protected $table = 'empresa_cliente_otros_documentos';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'empresa_cliente_id',
        'descripcion',
        'nombre_original',
        'ruta_archivo',
        'formato',
        'tamano_bytes',
        'subido_por',
        'fecha_subida',
    ];

    protected function casts(): array
    {
        return [
            'tamano_bytes' => 'integer',
            'fecha_subida' => 'datetime',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function empresaCliente(): BelongsTo
    {
        return $this->belongsTo(EmpresaCliente::class, 'empresa_cliente_id');
    }

    public function subidoPorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'subido_por');
    }
}
