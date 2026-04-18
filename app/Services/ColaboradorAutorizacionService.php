<?php

namespace App\Services;

use App\Models\EmpresaCliente;
use App\Models\Usuario;

class ColaboradorAutorizacionService
{
    public static function empresaAccesible(Usuario $u, int $empresaClienteId): ?EmpresaCliente
    {
        $empresa = EmpresaCliente::query()->find($empresaClienteId);
        if (! $empresa) {
            return null;
        }

        if ($u->tipo === 'consultora' && ($ec = $u->empresaConsultoraTitular)) {
            return $ec->id === $empresa->consultora_id ? $empresa : null;
        }

        $c = $u->colaborador;
        if (! $c) {
            return null;
        }

        $pivot = $c->empresasCliente()->whereKey($empresaClienteId)->wherePivot('activo', true)->first();

        return $pivot ? $empresa : null;
    }

    public static function esConsultoraTitularDeEmpresa(Usuario $u, EmpresaCliente $emp): bool
    {
        if ($u->tipo !== 'consultora') {
            return false;
        }
        $ec = $u->empresaConsultoraTitular;

        return $ec && $ec->id === $emp->consultora_id;
    }

    public static function puedeRegistrarPersonal(Usuario $u, int $empresaClienteId): bool
    {
        $emp = EmpresaCliente::query()->find($empresaClienteId);
        if (! $emp) {
            return false;
        }
        if (self::esConsultoraTitularDeEmpresa($u, $emp)) {
            return true;
        }
        $c = $u->colaborador;
        if (! $c) {
            return false;
        }
        if (! $c->empresasCliente()->whereKey($empresaClienteId)->wherePivot('activo', true)->exists()) {
            return false;
        }

        return $c->permisosPorModulo()->where('puede_registrar_personal', true)->exists();
    }

    public static function puedeEditarPersonal(Usuario $u, int $empresaClienteId): bool
    {
        $emp = EmpresaCliente::query()->find($empresaClienteId);
        if (! $emp) {
            return false;
        }
        if (self::esConsultoraTitularDeEmpresa($u, $emp)) {
            return true;
        }
        $c = $u->colaborador;
        if (! $c) {
            return false;
        }
        if (! $c->empresasCliente()->whereKey($empresaClienteId)->wherePivot('activo', true)->exists()) {
            return false;
        }

        return $c->permisosPorModulo()->where('puede_editar_personal', true)->exists();
    }

    public static function puedeEditarEmpresaCliente(Usuario $u, int $empresaClienteId): bool
    {
        $emp = EmpresaCliente::query()->find($empresaClienteId);
        if (! $emp) {
            return false;
        }
        if (self::esConsultoraTitularDeEmpresa($u, $emp)) {
            return true;
        }
        $c = $u->colaborador;
        if (! $c) {
            return false;
        }
        if (! $c->empresasCliente()->whereKey($empresaClienteId)->wherePivot('activo', true)->exists()) {
            return false;
        }

        return (bool) $c->puede_editar_empresa_cliente;
    }
}
