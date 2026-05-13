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

    /**
     * Subir documentos del legajo (catálogo por módulo): solo flag puede_subir_documentos del módulo.
     */
    public static function puedeSubirDocumentosEnModulo(Usuario $u, int $empresaClienteId, string $modulo): bool
    {
        if (! in_array($modulo, ['afp', 'caja', 'ministerio'], true)) {
            return false;
        }
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
        $p = $c->permisosPorModulo()->where('modulo', $modulo)->first();
        if (! $p) {
            return false;
        }

        return (bool) $p->puede_subir_documentos;
    }

    /**
     * Cargar o reemplazar una declaración mensual (por módulo): solo puede_gestionar_modulo del módulo.
     */
    public static function puedeCargarDeclaracionMensual(Usuario $u, int $empresaClienteId, string $modulo): bool
    {
        if (! in_array($modulo, ['afp', 'caja', 'ministerio'], true)) {
            return false;
        }

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
        $p = $c->permisosPorModulo()->where('modulo', $modulo)->first();
        if (! $p) {
            return false;
        }

        return (bool) $p->puede_gestionar_modulo;
    }

    /**
     * Declaración anual de aguinaldo: solo flag puede_declarar_aguinaldo en colaborador.
     */
    public static function puedeCargarDeclaracionAguinaldo(Usuario $u, int $empresaClienteId): bool
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

        return (bool) $c->puede_declarar_aguinaldo;
    }

    /**
     * PDFs varios asociados a la empresa desde el listado de personal (colaborador/consultora).
     */
    public static function puedeGestionarOtrosDocumentosEmpresa(Usuario $u, int $empresaClienteId): bool
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

        return (bool) $c->puede_gestionar_otros_documentos_empresa;
    }

    /**
     * PDFs del catálogo «Mi empresa» (NIT, ROE, etc.): carga/reemplazo solo colaborador/consultora con permiso explícito.
     */
    public static function puedeGestionarDocumentosLegalesMiEmpresa(Usuario $u, int $empresaClienteId): bool
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

        return (bool) $c->puede_gestionar_documentos_legales_mi_empresa;
    }
}
