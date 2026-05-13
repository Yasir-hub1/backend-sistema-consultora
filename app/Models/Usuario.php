<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario del sistema Consult-360 (autenticación central + Sanctum).
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

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class, 'usuario_id');
    }

    public function toApiArray(): array
    {
        $base = [
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

        if ($this->tipo === 'colaborador') {
            // Siempre leer de BD: si se usa la relación en memoria, los permisos quedan obsoletos
            // tras actualizarlos desde el portal consultora (Mi equipo).
            $col = Colaborador::query()
                ->where('usuario_id', $this->id)
                ->with('permisosPorModulo')
                ->first();
            if ($col) {
                $permisos = $col->permisosPorModulo;
                $base['colaborador'] = [
                    'id' => $col->id,
                    'puede_editar_empresa_cliente' => (bool) $col->puede_editar_empresa_cliente,
                    'puede_declarar_aguinaldo' => (bool) $col->puede_declarar_aguinaldo,
                    'puede_gestionar_otros_documentos_empresa' => (bool) $col->puede_gestionar_otros_documentos_empresa,
                    'puede_gestionar_documentos_legales_mi_empresa' => (bool) $col->puede_gestionar_documentos_legales_mi_empresa,
                    'permisos_por_modulo' => $permisos->map(static function ($p) {
                        return [
                            'modulo' => $p->modulo,
                            'puede_ver' => (bool) $p->puede_ver,
                            'puede_registrar_personal' => (bool) $p->puede_registrar_personal,
                            'puede_editar_personal' => (bool) $p->puede_editar_personal,
                            'puede_subir_documentos' => (bool) $p->puede_subir_documentos,
                            'puede_eliminar_documentos' => (bool) $p->puede_eliminar_documentos,
                            'puede_gestionar_modulo' => (bool) $p->puede_gestionar_modulo,
                            'puede_exportar_reportes' => (bool) $p->puede_exportar_reportes,
                            'puede_invitar_empresa' => (bool) $p->puede_invitar_empresa,
                        ];
                    })->values()->all(),
                    'puede_editar_personal' => $permisos->contains(fn ($p) => (bool) $p->puede_editar_personal),
                    'puede_registrar_personal' => $permisos->contains(fn ($p) => (bool) $p->puede_registrar_personal),
                ];
            }
        }

        return $base;
    }
}
