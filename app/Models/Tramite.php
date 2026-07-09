<?php

namespace App\Models;

use App\Support\TramiteFechas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tramite extends Model
{
    public const ESTADOS = ['pendiente', 'en_proceso', 'vencido', 'completado'];

    public const DIAS_PROXIMOS_VENCER = 7;

    protected $table = 'tramites';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'consultora_id',
        'empresa_cliente_id',
        'creado_por_usuario_id',
        'asignado_a_colaborador_id',
        'tipo',
        'nombre',
        'descripcion',
        'fecha_inicio',
        'fecha_vencimiento',
        'es_recurrente',
        'frecuencia',
        'dia_vencimiento_mes',
        'hora_vencimiento',
        'periodo_actual',
        'notificar_cada_periodo',
        'recurrencia_activa',
        'recurrencia_anulada_en',
        'anulado',
        'anulado_en',
        'estado',
        'progreso_pct',
        'completado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_vencimiento' => 'date',
            'es_recurrente' => 'boolean',
            'notificar_cada_periodo' => 'boolean',
            'recurrencia_activa' => 'boolean',
            'recurrencia_anulada_en' => 'datetime',
            'anulado' => 'boolean',
            'anulado_en' => 'datetime',
            'completado_en' => 'datetime',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function consultora(): BelongsTo
    {
        return $this->belongsTo(EmpresaConsultora::class, 'consultora_id');
    }

    public function empresaCliente(): BelongsTo
    {
        return $this->belongsTo(EmpresaCliente::class, 'empresa_cliente_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por_usuario_id');
    }

    public function asignadoA(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'asignado_a_colaborador_id');
    }

    public function colaboradoresAsignados(): BelongsToMany
    {
        return $this->belongsToMany(Colaborador::class, 'tramite_colaboradores', 'tramite_id', 'colaborador_id')
            ->withPivot('asignado_en');
    }

    public function tareas(): HasMany
    {
        return $this->hasMany(Tarea::class, 'tramite_id')->orderBy('orden');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(TramiteEvento::class, 'tramite_id')->orderBy('ocurrido_en');
    }

    public function scopeProximosAVencer($query, ?int $dias = null)
    {
        $dias ??= self::DIAS_PROXIMOS_VENCER;
        $hoy = TramiteFechas::hoySoloDia();
        $limite = TramiteFechas::ahora()->addDays($dias)->toDateString();

        return $query
            ->where('estado', '!=', 'completado')
            ->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '>=', $hoy)
            ->whereDate('fecha_vencimiento', '<=', $limite);
    }
}
