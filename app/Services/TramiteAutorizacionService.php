<?php

namespace App\Services;

use App\Models\EmpresaCliente;
use App\Models\Tramite;
use App\Models\Usuario;

class TramiteAutorizacionService
{
    public static function puedeVerTramite(Usuario $u, Tramite $tramite): bool
    {
        if ($u->tipo === 'consultora' && ($ec = $u->empresaConsultoraTitular)) {
            return $tramite->consultora_id === $ec->id;
        }

        if ($u->tipo === 'colaborador' && ($c = $u->colaborador)) {
            if ($tramite->consultora_id !== $c->consultora_id) {
                return false;
            }

            return $c->empresasCliente()
                ->whereKey($tramite->empresa_cliente_id)
                ->wherePivot('activo', true)
                ->exists();
        }

        if ($u->tipo === 'empresa_cliente' && ($emp = $u->empresaClienteComoUsuario)) {
            return $tramite->empresa_cliente_id === $emp->id;
        }

        return false;
    }

    public static function puedeGestionarTramite(Usuario $u, Tramite $tramite): bool
    {
        if ($u->tipo === 'empresa_cliente') {
            return false;
        }

        return self::puedeVerTramite($u, $tramite);
    }

    public static function empresaAccesibleParaTramite(Usuario $u, int $empresaClienteId): ?EmpresaCliente
    {
        return ColaboradorAutorizacionService::empresaAccesible($u, $empresaClienteId);
    }

    public static function consultoraIdDeUsuario(Usuario $u): ?int
    {
        if ($u->tipo === 'consultora' && ($ec = $u->empresaConsultoraTitular)) {
            return $ec->id;
        }
        if ($u->tipo === 'colaborador' && ($c = $u->colaborador)) {
            return $c->consultora_id;
        }

        return null;
    }
}
