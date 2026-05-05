<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclaracionAguinaldo extends Model
{
    protected $table = 'declaraciones_aguinaldo';

    protected $fillable = [
        'empresa_cliente_id',
        'anio',
        'nombre_archivo',
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
            'anio' => 'integer',
            'tamano_bytes' => 'integer',
            'fecha_subida' => 'datetime',
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
