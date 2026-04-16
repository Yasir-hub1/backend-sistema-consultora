<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Archivo subido asociado a un empleado y a un tipo de documento.
 */
class Documento extends Model
{
    protected $table = 'documentos';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'personal_id',
        'tipo_documento_id',
        'modulo',
        'nombre_archivo',
        'nombre_original',
        'ruta_archivo',
        'formato',
        'tamano_bytes',
        'periodo',
        'fecha_documento',
        'observacion',
        'es_vigente',
        'subido_por',
        'fecha_subida',
        'eliminado',
        'eliminado_por',
        'eliminado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_documento' => 'date',
            'es_vigente' => 'boolean',
            'fecha_subida' => 'datetime',
            'eliminado' => 'boolean',
            'eliminado_en' => 'datetime',
        ];
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }

    public function subidoPorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'subido_por');
    }

    public function eliminadoPorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'eliminado_por');
    }
}
