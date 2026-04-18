<?php

use App\Http\Controllers\Api\Admin\EmpresaConsultoraController;
use App\Http\Controllers\Api\Admin\EstadisticaController;
use App\Http\Controllers\Api\Auth\LaboraAuthController;
use App\Http\Controllers\Api\Colaborador\AlertaController as ColaboradorAlertaController;
use App\Http\Controllers\Api\Colaborador\DashboardController as ColaboradorDashboardController;
use App\Http\Controllers\Api\Colaborador\DocumentoModuloController;
use App\Http\Controllers\Api\Colaborador\EmpresaAsignadaController;
use App\Http\Controllers\Api\Colaborador\PersonalController as ColaboradorPersonalController;
use App\Http\Controllers\Api\Consultora\AlertaController;
use App\Http\Controllers\Api\Consultora\CatalogoConsultoraController;
use App\Http\Controllers\Api\Consultora\ConfiguracionController;
use App\Http\Controllers\Api\Consultora\EmpresaClienteController as ConsultoraEmpresaClienteController;
use App\Http\Controllers\Api\Consultora\MiEquipoController;
use App\Http\Controllers\Api\Consultora\TiposDocumentoController;
use App\Http\Controllers\Api\EmpresaCliente\DashboardController as EmpresaClienteDashboardController;
use App\Http\Controllers\Api\EmpresaCliente\DocumentoDescargaController;
use App\Http\Controllers\Api\EmpresaCliente\MiConsultoraController;
use App\Http\Controllers\Api\EmpresaCliente\PersonalController as EmpresaClientePersonalController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [LaboraAuthController::class, 'login']);
Route::post('/auth/activar', [LaboraAuthController::class, 'activar']);
Route::post('/auth/primer-acceso', [LaboraAuthController::class, 'primerAcceso']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [LaboraAuthController::class, 'logout']);
    Route::get('/auth/perfil', [LaboraAuthController::class, 'perfil']);
    Route::post('/auth/cambiar-contrasena-inicial', [LaboraAuthController::class, 'cambiarContrasenaInicial']);
});

Route::middleware(['auth:sanctum', 'usuario.tipo:administrador'])->prefix('admin')->group(function () {
    Route::get('/estadisticas', EstadisticaController::class);
    Route::get('/empresas-consultoras', [EmpresaConsultoraController::class, 'index']);
    Route::post('/empresas-consultoras', [EmpresaConsultoraController::class, 'store']);
    Route::get('/empresas-consultoras/{id}', [EmpresaConsultoraController::class, 'show'])->whereNumber('id');
    Route::put('/empresas-consultoras/{id}', [EmpresaConsultoraController::class, 'update'])->whereNumber('id');
    Route::patch('/empresas-consultoras/{id}/acceso-usuario', [EmpresaConsultoraController::class, 'updateAccesoUsuario'])->whereNumber('id');
    Route::post('/empresas-consultoras/{id}/reenviar-activacion', [EmpresaConsultoraController::class, 'reenviarActivacion'])->whereNumber('id');
});

Route::middleware(['auth:sanctum', 'usuario.tipo:consultora'])->prefix('consultora')->group(function () {
    Route::get('/catalogos/instituciones-financieras', [CatalogoConsultoraController::class, 'institucionesFinancieras']);
    Route::get('/catalogos/tipos-documento', [TiposDocumentoController::class, 'index']);
    Route::post('/catalogos/tipos-documento', [TiposDocumentoController::class, 'store']);
    Route::put('/catalogos/tipos-documento/{id}', [TiposDocumentoController::class, 'update'])->whereNumber('id');
    Route::delete('/catalogos/tipos-documento/{id}', [TiposDocumentoController::class, 'destroy'])->whereNumber('id');

    Route::get('/configuracion', [ConfiguracionController::class, 'show']);
    Route::put('/configuracion/paso/{paso}', [ConfiguracionController::class, 'guardarPaso'])->whereNumber('paso');
    Route::post('/configuracion/logo', [ConfiguracionController::class, 'subirLogo']);
    Route::post('/configuracion/finalizar', [ConfiguracionController::class, 'finalizar']);

    Route::get('/colaboradores', [MiEquipoController::class, 'index']);
    Route::post('/colaboradores', [MiEquipoController::class, 'store']);
    Route::put('/colaboradores/{id}/permisos', [MiEquipoController::class, 'updatePermisos'])->whereNumber('id');
    Route::patch('/colaboradores/{id}/acceso', [MiEquipoController::class, 'updateAcceso'])->whereNumber('id');

    Route::get('/empresas-cliente', [ConsultoraEmpresaClienteController::class, 'index']);
    Route::post('/empresas-cliente', [ConsultoraEmpresaClienteController::class, 'store']);
    Route::get('/empresas-cliente/{id}', [ConsultoraEmpresaClienteController::class, 'show'])->whereNumber('id');
    Route::patch('/empresas-cliente/{id}', [ConsultoraEmpresaClienteController::class, 'update'])->whereNumber('id');
    Route::post('/empresas-cliente/{id}/generar-acceso', [ConsultoraEmpresaClienteController::class, 'generarAcceso'])->whereNumber('id');
    Route::patch('/empresas-cliente/{id}/acceso-portal', [ConsultoraEmpresaClienteController::class, 'updateAccesoPortal'])->whereNumber('id');
    Route::put('/empresas-cliente/{id}/asignaciones', [ConsultoraEmpresaClienteController::class, 'asignaciones'])->whereNumber('id');

    Route::get('/alertas', [AlertaController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'usuario.tipo:colaborador,consultora'])->prefix('colaborador')->group(function () {
    Route::get('/dashboard', ColaboradorDashboardController::class);
    Route::get('/empresas-cliente', [EmpresaAsignadaController::class, 'index']);
    Route::patch('/empresas-cliente/{empresaClienteId}', [EmpresaAsignadaController::class, 'update'])->whereNumber('empresaClienteId');
    Route::get('/alertas', [ColaboradorAlertaController::class, 'index']);
    Route::get('/modulos/{modulo}/tipos-documento', [DocumentoModuloController::class, 'tipos']);

    Route::get('/empresas-cliente/{empresaClienteId}/personal', [ColaboradorPersonalController::class, 'index'])->whereNumber('empresaClienteId');
    Route::post('/empresas-cliente/{empresaClienteId}/personal', [ColaboradorPersonalController::class, 'store'])->whereNumber('empresaClienteId');
    Route::get('/empresas-cliente/{empresaClienteId}/personal/{personalId}', [ColaboradorPersonalController::class, 'show'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('personalId');
    Route::patch('/empresas-cliente/{empresaClienteId}/personal/{personalId}/caja-regimen', [ColaboradorPersonalController::class, 'patchRegimenCaja'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('personalId');
    Route::patch('/empresas-cliente/{empresaClienteId}/personal/{personalId}', [ColaboradorPersonalController::class, 'update'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('personalId');

    Route::get('/empresas-cliente/{empresaClienteId}/personal/{personalId}/modulos/{modulo}/documentos', [DocumentoModuloController::class, 'index'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('personalId')
        ->whereIn('modulo', ['afp', 'caja', 'ministerio']);
    Route::post('/empresas-cliente/{empresaClienteId}/personal/{personalId}/modulos/{modulo}/documentos', [DocumentoModuloController::class, 'store'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('personalId')
        ->whereIn('modulo', ['afp', 'caja', 'ministerio']);
});

Route::middleware(['auth:sanctum', 'usuario.tipo:empresa_cliente'])->prefix('empresa-cliente')->group(function () {
    Route::get('/dashboard', EmpresaClienteDashboardController::class);
    Route::get('/mi-consultora', MiConsultoraController::class);
    Route::get('/personal', [EmpresaClientePersonalController::class, 'index']);
    Route::get('/personal/{personalId}', [EmpresaClientePersonalController::class, 'show'])->whereNumber('personalId');
    Route::get('/documentos/{documento}/descargar', [DocumentoDescargaController::class, 'url'])->whereNumber('documento');
    Route::get('/documentos/{documento}/stream', [DocumentoDescargaController::class, 'stream'])->whereNumber('documento');
});
