<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario del sistema LaboraConsult (autenticación central + Sanctum).
 *
 * Tipos: administrador, consultora, colaborador, empresa_cliente.
 */
class Usuario extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;

    protected $table = 'usuarios';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'nombre_usuario',
        'correo',
        'contrasena_hash',
        'tipo',
        'estado',
        'verificado',
        'debe_cambiar_contrasena',
        'token_activacion',
        'token_activacion_exp',
        'token_reset',
        'token_reset_exp',
        'ultimo_acceso',
        'ip_ultimo_acceso',
        'intentos_fallidos',
        'bloqueado_hasta',
    ];

    protected $hidden = [
        'contrasena_hash',
        'token_activacion',
        'token_reset',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'verificado' => 'boolean',
            'debe_cambiar_contrasena' => 'boolean',
            'token_activacion_exp' => 'datetime',
            'token_reset_exp' => 'datetime',
            'ultimo_acceso' => 'datetime',
            'bloqueado_hasta' => 'datetime',
            'creado_en' => 'datetime',
            'actualizado_en' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->contrasena_hash;
    }

    public function administrador(): HasOne
    {
        return $this->hasOne(Administrador::class, 'usuario_id');
    }

    public function empresaConsultoraTitular(): HasOne
    {
        return $this->hasOne(EmpresaConsultora::class, 'usuario_id');
    }

    public function colaborador(): HasOne
    {
        return $this->hasOne(Colaborador::class, 'usuario_id');
    }

    public function empresaClienteComoUsuario(): HasOne
    {
        return $this->hasOne(EmpresaCliente::class, 'usuario_id');
    }

    public function actividadLogs(): HasMany
    {
        return $this->hasMany(ActividadLog::class, 'usuario_id');
    }

    public function sesionesLaboraConsult(): HasMany
    {
        return $this->hasMany(Sesion::class, 'usuario_id');
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'nombre_usuario' => $this->nombre_usuario,
            'correo' => $this->correo,
            'email' => $this->correo,
            'tipo' => $this->tipo,
            'rol' => $this->tipo,
            'estado' => $this->estado,
            'verificado' => $this->verificado,
            'debe_cambiar_contrasena' => (bool) $this->debe_cambiar_contrasena,
            'debe_cambiar_password' => (bool) $this->debe_cambiar_contrasena,
        ];
    }
}
