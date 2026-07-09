<?php

use App\Http\Controllers\Api\Admin\EmpresaConsultoraController;
use App\Http\Controllers\Api\Admin\EstadisticaController;
use App\Http\Controllers\Api\Auth\LaboraAuthController;
use App\Http\Controllers\Api\Colaborador\AlertaController as ColaboradorAlertaController;
use App\Http\Controllers\Api\Colaborador\DashboardController as ColaboradorDashboardController;
use App\Http\Controllers\Api\Colaborador\DeclaracionAguinaldoController;
use App\Http\Controllers\Api\Colaborador\DeclaracionMensualController;
use App\Http\Controllers\Api\Colaborador\DocumentoModuloController;
use App\Http\Controllers\Api\Colaborador\EmpresaAsignadaController;
use App\Http\Controllers\Api\Colaborador\EmpresaClienteMiEmpresaDocumentoController;
use App\Http\Controllers\Api\Colaborador\EmpresaClienteOtroDocumentoController;
use App\Http\Controllers\Api\Colaborador\PersonalController as ColaboradorPersonalController;
use App\Http\Controllers\Api\Consultora\AlertaController;
use App\Http\Controllers\Api\Consultora\CatalogoConsultoraController;
use App\Http\Controllers\Api\Consultora\ConfiguracionController;
use App\Http\Controllers\Api\Consultora\EmpresaClienteController as ConsultoraEmpresaClienteController;
use App\Http\Controllers\Api\Consultora\MiEquipoController;
use App\Http\Controllers\Api\Consultora\ReporteDeclaracionController;
use App\Http\Controllers\Api\Consultora\ReporteResumenAportesController;
use App\Http\Controllers\Api\Consultora\TiposDocumentoController;
use App\Http\Controllers\Api\EmpresaCliente\AlertaController as EmpresaClienteAlertaController;
use App\Http\Controllers\Api\EmpresaCliente\DashboardController as EmpresaClienteDashboardController;
use App\Http\Controllers\Api\EmpresaCliente\DeclaracionAguinaldoController as EmpresaClienteDeclaracionAguinaldoController;
use App\Http\Controllers\Api\EmpresaCliente\DeclaracionMensualController as EmpresaClienteDeclaracionMensualController;
use App\Http\Controllers\Api\EmpresaCliente\DocumentoDescargaController;
use App\Http\Controllers\Api\EmpresaCliente\MiEmpresaDocumentoController;
use App\Http\Controllers\Api\EmpresaCliente\MiConsultoraController;
use App\Http\Controllers\Api\EmpresaCliente\OtrosDocumentosController as EmpresaClienteOtrosDocumentosController;
use App\Http\Controllers\Api\EmpresaCliente\PersonalController as EmpresaClientePersonalController;
use App\Http\Controllers\Api\TramiteController;
use App\Http\Controllers\Api\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [LaboraAuthController::class, 'login']);
Route::post('/auth/activar', [LaboraAuthController::class, 'activar']);
Route::post('/auth/primer-acceso', [LaboraAuthController::class, 'primerAcceso']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [LaboraAuthController::class, 'logout']);
    Route::get('/auth/perfil', [LaboraAuthController::class, 'perfil']);
    Route::post('/auth/cambiar-contrasena-inicial', [LaboraAuthController::class, 'cambiarContrasenaInicial']);
    Route::get('/push/public-key', [PushSubscriptionController::class, 'publicKey']);
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store']);
    Route::delete('/push/unsubscribe', [PushSubscriptionController::class, 'destroy']);
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
    Route::get('/colaboradores/plantilla-registro-masivo', [MiEquipoController::class, 'descargarPlantillaRegistroMasivo']);
    Route::post('/colaboradores/registro-masivo', [MiEquipoController::class, 'cargarRegistroMasivo']);
    Route::put('/colaboradores/{id}/permisos', [MiEquipoController::class, 'updatePermisos'])->whereNumber('id');
    Route::patch('/colaboradores/{id}/acceso', [MiEquipoController::class, 'updateAcceso'])->whereNumber('id');

    Route::get('/empresas-cliente', [ConsultoraEmpresaClienteController::class, 'index']);
    Route::post('/empresas-cliente', [ConsultoraEmpresaClienteController::class, 'store']);
    Route::get('/empresas-cliente/{id}', [ConsultoraEmpresaClienteController::class, 'show'])->whereNumber('id');
    Route::put('/empresas-cliente/{id}', [ConsultoraEmpresaClienteController::class, 'update'])->whereNumber('id');
    Route::patch('/empresas-cliente/{id}', [ConsultoraEmpresaClienteController::class, 'update'])->whereNumber('id');
    Route::post('/empresas-cliente/{id}/generar-acceso', [ConsultoraEmpresaClienteController::class, 'generarAcceso'])->whereNumber('id');
    Route::patch('/empresas-cliente/{id}/acceso-portal', [ConsultoraEmpresaClienteController::class, 'updateAccesoPortal'])->whereNumber('id');
    Route::put('/empresas-cliente/{id}/asignaciones', [ConsultoraEmpresaClienteController::class, 'asignaciones'])->whereNumber('id');

    Route::get('/alertas', [AlertaController::class, 'index']);
    Route::patch('/alertas/marcar-todas-leidas', [AlertaController::class, 'marcarTodasLeidas']);
    Route::patch('/alertas/{id}/marcar-leida', [AlertaController::class, 'marcarLeida'])->whereNumber('id');
    Route::get('/reportes/empresas-cliente', [ReporteDeclaracionController::class, 'empresas']);
    Route::get('/reportes/declaraciones', [ReporteDeclaracionController::class, 'index']);
    Route::get('/reportes/declaraciones/{id}/vista-previa', [ReporteDeclaracionController::class, 'vistaPrevia'])->whereNumber('id');
    Route::get('/reportes/declaraciones/{id}/descargar', [ReporteDeclaracionController::class, 'descargar'])->whereNumber('id');
    Route::post('/reportes/declaraciones/exportar-pdf', [ReporteDeclaracionController::class, 'exportarPdf']);
    Route::get('/reportes/resumen-aportes', [ReporteResumenAportesController::class, 'datos']);
    Route::get('/reportes/resumen-aportes/pdf', [ReporteResumenAportesController::class, 'pdf']);

    Route::get('/tramites/tipos', [TramiteController::class, 'tipos']);
    Route::get('/tramites/colaboradores-asignables', [TramiteController::class, 'colaboradoresAsignables']);
    Route::get('/tramites/resumen', [TramiteController::class, 'resumen']);
    Route::get('/tramites/calendario', [TramiteController::class, 'calendario']);
    Route::get('/tramites', [TramiteController::class, 'index']);
    Route::post('/tramites', [TramiteController::class, 'store']);
    Route::get('/tramites/{id}', [TramiteController::class, 'show'])->whereNumber('id');
    Route::patch('/tramites/{id}', [TramiteController::class, 'update'])->whereNumber('id');
    Route::post('/tramites/{id}/anular-recurrencia', [TramiteController::class, 'anularRecurrencia'])->whereNumber('id');
    Route::post('/tramites/{id}/anular', [TramiteController::class, 'anularTramite'])->whereNumber('id');
    Route::post('/tramites/{tramiteId}/tareas/{tareaId}/iniciar', [TramiteController::class, 'iniciarTarea'])->whereNumber(['tramiteId', 'tareaId']);
    Route::post('/tramites/{tramiteId}/tareas/{tareaId}/completar', [TramiteController::class, 'completarTarea'])->whereNumber(['tramiteId', 'tareaId']);
    Route::post('/tramites/{tramiteId}/tareas/{tareaId}/documentos', [TramiteController::class, 'subirDocumentoTarea'])->whereNumber(['tramiteId', 'tareaId']);
    Route::get('/tramites/{tramiteId}/tareas/{tareaId}/documentos/{documentoId}/descargar', [TramiteController::class, 'descargarDocumentoTarea'])->whereNumber(['tramiteId', 'tareaId', 'documentoId']);
});

Route::middleware(['auth:sanctum', 'usuario.tipo:colaborador,consultora'])->prefix('colaborador')->group(function () {
    Route::get('/dashboard', ColaboradorDashboardController::class);
    Route::get('/empresas-cliente', [EmpresaAsignadaController::class, 'index']);
    Route::patch('/empresas-cliente/{empresaClienteId}', [EmpresaAsignadaController::class, 'update'])->whereNumber('empresaClienteId');
    Route::get('/alertas', [ColaboradorAlertaController::class, 'index']);
    Route::patch('/alertas/marcar-todas-leidas', [ColaboradorAlertaController::class, 'marcarTodasLeidas']);
    Route::patch('/alertas/{id}/marcar-leida', [ColaboradorAlertaController::class, 'marcarLeida'])->whereNumber('id');
    Route::get('/modulos/{modulo}/tipos-documento', [DocumentoModuloController::class, 'tipos']);

    Route::get('/empresas-cliente/{empresaClienteId}/declaraciones-mensuales', [DeclaracionMensualController::class, 'index'])
        ->whereNumber('empresaClienteId');
    Route::post('/empresas-cliente/{empresaClienteId}/declaraciones-mensuales', [DeclaracionMensualController::class, 'store'])
        ->whereNumber('empresaClienteId');
    Route::get('/empresas-cliente/{empresaClienteId}/declaraciones-mensuales/{id}/vista-previa', [DeclaracionMensualController::class, 'vistaPrevia'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('id');
    Route::get('/empresas-cliente/{empresaClienteId}/declaraciones-mensuales/{id}/descargar', [DeclaracionMensualController::class, 'descargar'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('id');

    Route::get('/empresas-cliente/{empresaClienteId}/declaraciones-aguinaldo', [DeclaracionAguinaldoController::class, 'index'])
        ->whereNumber('empresaClienteId');
    Route::post('/empresas-cliente/{empresaClienteId}/declaraciones-aguinaldo', [DeclaracionAguinaldoController::class, 'store'])
        ->whereNumber('empresaClienteId');
    Route::get('/empresas-cliente/{empresaClienteId}/declaraciones-aguinaldo/{id}/vista-previa', [DeclaracionAguinaldoController::class, 'vistaPrevia'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('id');
    Route::get('/empresas-cliente/{empresaClienteId}/declaraciones-aguinaldo/{id}/descargar', [DeclaracionAguinaldoController::class, 'descargar'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('id');

    Route::get('/empresas-cliente/{empresaClienteId}/personal', [ColaboradorPersonalController::class, 'index'])->whereNumber('empresaClienteId');
    Route::post('/empresas-cliente/{empresaClienteId}/personal', [ColaboradorPersonalController::class, 'store'])->whereNumber('empresaClienteId');
    Route::get('/empresas-cliente/{empresaClienteId}/personal/plantilla-registro-masivo', [ColaboradorPersonalController::class, 'descargarPlantillaRegistroMasivo'])
        ->whereNumber('empresaClienteId');
    Route::post('/empresas-cliente/{empresaClienteId}/personal/registro-masivo', [ColaboradorPersonalController::class, 'cargarRegistroMasivo'])
        ->whereNumber('empresaClienteId');
    Route::get('/empresas-cliente/{empresaClienteId}/otros-documentos', [EmpresaClienteOtroDocumentoController::class, 'index'])
        ->whereNumber('empresaClienteId');
    Route::post('/empresas-cliente/{empresaClienteId}/otros-documentos', [EmpresaClienteOtroDocumentoController::class, 'store'])
        ->whereNumber('empresaClienteId');
    Route::get('/empresas-cliente/{empresaClienteId}/otros-documentos/{id}/vista-previa', [EmpresaClienteOtroDocumentoController::class, 'vistaPrevia'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('id');
    Route::get('/empresas-cliente/{empresaClienteId}/otros-documentos/{id}/descargar', [EmpresaClienteOtroDocumentoController::class, 'descargar'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('id');

    Route::get('/empresas-cliente/{empresaClienteId}/mi-empresa/documentos', [EmpresaClienteMiEmpresaDocumentoController::class, 'index'])
        ->whereNumber('empresaClienteId');
    Route::post('/empresas-cliente/{empresaClienteId}/mi-empresa/documentos/{tipo}', [EmpresaClienteMiEmpresaDocumentoController::class, 'store'])
        ->whereNumber('empresaClienteId');
    Route::get('/empresas-cliente/{empresaClienteId}/mi-empresa/documentos/{tipo}/vista-previa', [EmpresaClienteMiEmpresaDocumentoController::class, 'vistaPrevia'])
        ->whereNumber('empresaClienteId');
    Route::get('/empresas-cliente/{empresaClienteId}/mi-empresa/documentos/{tipo}/descargar', [EmpresaClienteMiEmpresaDocumentoController::class, 'descargar'])
        ->whereNumber('empresaClienteId');

    Route::get('/empresas-cliente/{empresaClienteId}/personal/{personalId}', [ColaboradorPersonalController::class, 'show'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('personalId');
    Route::get('/empresas-cliente/{empresaClienteId}/personal/{personalId}/legajo/{tipo}/stream', [ColaboradorPersonalController::class, 'streamLegajoArchivo'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('personalId')
        ->whereIn('tipo', ['curriculum', 'licencia', 'aviso', 'croquis', 'certificado_nacimiento']);
    Route::patch('/empresas-cliente/{empresaClienteId}/personal/{personalId}/caja-regimen', [ColaboradorPersonalController::class, 'patchRegimenCaja'])
        ->whereNumber('empresaClienteId')
        ->whereNumber('personalId');
    // POST: mismo controlador que PATCH — multipart con archivos suele llegar vacío con PATCH en PHP-FPM/proxies.
    Route::post('/empresas-cliente/{empresaClienteId}/personal/{personalId}', [ColaboradorPersonalController::class, 'update'])
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

    Route::get('/tramites/tipos', [TramiteController::class, 'tipos']);
    Route::get('/tramites/colaboradores-asignables', [TramiteController::class, 'colaboradoresAsignables']);
    Route::get('/tramites/resumen', [TramiteController::class, 'resumen']);
    Route::get('/tramites/calendario', [TramiteController::class, 'calendario']);
    Route::get('/tramites', [TramiteController::class, 'index']);
    Route::post('/tramites', [TramiteController::class, 'store']);
    Route::get('/tramites/{id}', [TramiteController::class, 'show'])->whereNumber('id');
    Route::patch('/tramites/{id}', [TramiteController::class, 'update'])->whereNumber('id');
    Route::post('/tramites/{id}/anular-recurrencia', [TramiteController::class, 'anularRecurrencia'])->whereNumber('id');
    Route::post('/tramites/{id}/anular', [TramiteController::class, 'anularTramite'])->whereNumber('id');
    Route::post('/tramites/{tramiteId}/tareas/{tareaId}/iniciar', [TramiteController::class, 'iniciarTarea'])->whereNumber(['tramiteId', 'tareaId']);
    Route::post('/tramites/{tramiteId}/tareas/{tareaId}/completar', [TramiteController::class, 'completarTarea'])->whereNumber(['tramiteId', 'tareaId']);
    Route::post('/tramites/{tramiteId}/tareas/{tareaId}/documentos', [TramiteController::class, 'subirDocumentoTarea'])->whereNumber(['tramiteId', 'tareaId']);
    Route::get('/tramites/{tramiteId}/tareas/{tareaId}/documentos/{documentoId}/descargar', [TramiteController::class, 'descargarDocumentoTarea'])->whereNumber(['tramiteId', 'tareaId', 'documentoId']);
});

Route::middleware(['auth:sanctum', 'usuario.tipo:empresa_cliente'])->prefix('empresa-cliente')->group(function () {
    Route::get('/dashboard', EmpresaClienteDashboardController::class);
    Route::get('/mi-consultora', MiConsultoraController::class);
    Route::get('/mi-empresa/documentos', [MiEmpresaDocumentoController::class, 'index']);
    Route::get('/mi-empresa/documentos/{tipo}/vista-previa', [MiEmpresaDocumentoController::class, 'vistaPrevia']);
    Route::get('/mi-empresa/documentos/{tipo}/descargar', [MiEmpresaDocumentoController::class, 'descargar']);
    Route::get('/alertas', [EmpresaClienteAlertaController::class, 'index']);
    Route::patch('/alertas/marcar-todas-leidas', [EmpresaClienteAlertaController::class, 'marcarTodasLeidas']);
    Route::patch('/alertas/{id}/marcar-leida', [EmpresaClienteAlertaController::class, 'marcarLeida'])->whereNumber('id');
    Route::get('/personal', [EmpresaClientePersonalController::class, 'index']);
    Route::get('/personal/{personalId}', [EmpresaClientePersonalController::class, 'show'])->whereNumber('personalId');
    Route::get('/documentos/{documento}/descargar', [DocumentoDescargaController::class, 'url'])->whereNumber('documento');
    Route::get('/documentos/{documento}/stream', [DocumentoDescargaController::class, 'stream'])->whereNumber('documento');

    Route::get('/declaraciones-mensuales', [EmpresaClienteDeclaracionMensualController::class, 'index']);
    Route::post('/declaraciones-mensuales/descarga-zip', [EmpresaClienteDeclaracionMensualController::class, 'descargarZip']);
    Route::get('/declaraciones-mensuales/{id}/vista-previa', [EmpresaClienteDeclaracionMensualController::class, 'vistaPrevia'])
        ->whereNumber('id');
    Route::get('/declaraciones-mensuales/{id}/descargar', [EmpresaClienteDeclaracionMensualController::class, 'descargar'])
        ->whereNumber('id');
    Route::get('/declaraciones-aguinaldo', [EmpresaClienteDeclaracionAguinaldoController::class, 'index']);
    Route::get('/declaraciones-aguinaldo/{id}/vista-previa', [EmpresaClienteDeclaracionAguinaldoController::class, 'vistaPrevia'])
        ->whereNumber('id');
    Route::get('/declaraciones-aguinaldo/{id}/descargar', [EmpresaClienteDeclaracionAguinaldoController::class, 'descargar'])
        ->whereNumber('id');
    Route::get('/otros-documentos', [EmpresaClienteOtrosDocumentosController::class, 'index']);
    Route::get('/otros-documentos/{id}/vista-previa', [EmpresaClienteOtrosDocumentosController::class, 'vistaPrevia'])
        ->whereNumber('id');
    Route::get('/otros-documentos/{id}/descargar', [EmpresaClienteOtrosDocumentosController::class, 'descargar'])
        ->whereNumber('id');

    Route::get('/tramites/resumen', [TramiteController::class, 'resumen']);
    Route::get('/tramites/calendario', [TramiteController::class, 'calendario']);
    Route::get('/tramites', [TramiteController::class, 'index']);
    Route::get('/tramites/{id}', [TramiteController::class, 'show'])->whereNumber('id');
    Route::get('/tramites/{tramiteId}/tareas/{tareaId}/documentos/{documentoId}/descargar', [TramiteController::class, 'descargarDocumentoTarea'])->whereNumber(['tramiteId', 'tareaId', 'documentoId']);
});
