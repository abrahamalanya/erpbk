<?php

use App\Modules\Caja\Http\Controllers\BilletajeController;
use App\Modules\Caja\Http\Controllers\BovedaController;
use App\Modules\Caja\Http\Controllers\CajaController;
use App\Modules\Caja\Http\Controllers\CuentaBancariaController;
use App\Modules\Cliente\Http\Controllers\ClienteController;
use App\Modules\CreditoPrendario\Http\Controllers\BienController;
use App\Modules\CreditoPrendario\Http\Controllers\ConfiguracionCreditoPrendarioController;
use App\Modules\CreditoPrendario\Http\Controllers\CreditoPrendarioController;
use App\Modules\Empresa\Http\Controllers\AgenciaController;
use App\Modules\Empresa\Http\Controllers\EmpresaController;
use App\Modules\Sistemas\Http\Controllers\AuthController;
use App\Modules\Sistemas\Http\Controllers\ConceptoController;
use App\Modules\Sistemas\Http\Controllers\NotificacionController;
use App\Modules\Sistemas\Http\Controllers\PermissionController;
use App\Modules\Sistemas\Http\Controllers\RoleController;
use App\Modules\Tienda\Http\Controllers\TiendaController;
use App\Modules\Usuario\Http\Controllers\UserController;
use App\Nucleo\Http\Controllers\BancoController;
use Illuminate\Support\Facades\Route;

// ===== AUTH ROUTES (públicas) =====
Route::post('/auth/login', [AuthController::class, 'login']);

// ===== TIENDA VIRTUAL (públicas, sin auth) =====
Route::prefix('tienda')->group(function () {
    Route::get('bienes', [TiendaController::class, 'index'])->name('tienda.bienes.index');
    Route::get('bienes/{bien}', [TiendaController::class, 'show'])->name('tienda.bienes.show');
    Route::post('bienes/{bien}/interes', [TiendaController::class, 'interes'])->name('tienda.bienes.interes');
});

// ===== PROTECTED ROUTES (requieren autenticación) =====
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('empresas', EmpresaController::class);
    Route::apiResource('agencias', AgenciaController::class);
    Route::apiResource('usuarios', UserController::class)->parameters(['usuarios' => 'user']);
    Route::apiResource('roles', RoleController::class)->only(['index', 'show', 'update']);
    Route::apiResource('permisos', PermissionController::class)->only(['index']);

    Route::get('notificaciones', [NotificacionController::class, 'index'])->name('notificaciones.index');
    Route::post('notificaciones/{notificacion}/marcar-leido', [NotificacionController::class, 'marcarLeido'])->name('notificaciones.marcar-leido');
    Route::post('notificaciones/marcar-todas-leidas', [NotificacionController::class, 'marcarTodasLeidas'])->name('notificaciones.marcar-todas-leidas');

    Route::apiResource('clientes', ClienteController::class);
    Route::post('clientes/{cliente}/asignar', [ClienteController::class, 'asignar'])->name('clientes.asignar');
    Route::get('clientes/consultar-dni/{dni}', [ClienteController::class, 'consultarDni'])->name('clientes.consultar-dni');

    Route::get('caja', [CajaController::class, 'miCaja'])->name('caja.mia');
    Route::post('caja/aperturar', [CajaController::class, 'aperturar'])->name('caja.aperturar');
    Route::post('caja/cerrar', [CajaController::class, 'cerrar'])->name('caja.cerrar');
    Route::get('caja/cierre/resumen', [CajaController::class, 'resumenCierre'])->name('caja.cierre.resumen');
    Route::get('caja/movimientos', [CajaController::class, 'movimientos'])->name('caja.movimientos.index');
    Route::post('caja/movimientos', [CajaController::class, 'registrarMovimiento'])->name('caja.movimientos.registrar');
    Route::apiResource('cajas', CajaController::class)->only(['index', 'show']);
    Route::post('cajas/{caja}/cerrar-forzado', [CajaController::class, 'cerrarForzado'])->name('cajas.cerrar-forzado');
    Route::post('cajas/{caja}/reabrir', [CajaController::class, 'reabrir'])->name('cajas.reabrir');

    Route::get('bovedas/mia', [BovedaController::class, 'mia'])->name('bovedas.mia');
    Route::apiResource('bovedas', BovedaController::class)->only(['index', 'show']);
    Route::post('bovedas/{boveda}/cerrar', [BovedaController::class, 'cerrar'])->name('bovedas.cerrar');
    Route::post('bovedas/{boveda}/aperturar', [BovedaController::class, 'aperturar'])->name('bovedas.aperturar');
    Route::post('bovedas/{boveda}/inyectar', [BovedaController::class, 'inyectar'])->name('bovedas.inyectar');
    Route::get('bovedas/{boveda}/inyecciones', [BovedaController::class, 'inyecciones'])->name('bovedas.inyecciones');
    Route::delete('bovedas/{boveda}/inyecciones/{movimiento}', [BovedaController::class, 'eliminarInyeccion'])->name('bovedas.inyecciones.eliminar');
    Route::post('bovedas/{boveda}/reabrir', [BovedaController::class, 'reabrir'])->name('bovedas.reabrir');

    Route::apiResource('bancos', BancoController::class);

    Route::get('bovedas/{boveda}/cuentas-bancarias', [CuentaBancariaController::class, 'index'])->name('bovedas.cuentas-bancarias.index');
    Route::post('bovedas/{boveda}/cuentas-bancarias', [CuentaBancariaController::class, 'store'])->name('bovedas.cuentas-bancarias.store');
    Route::get('cuentas-bancarias/{cuentaBancaria}', [CuentaBancariaController::class, 'show'])->name('cuentas-bancarias.show');
    Route::put('cuentas-bancarias/{cuentaBancaria}', [CuentaBancariaController::class, 'update'])->name('cuentas-bancarias.update');
    Route::delete('cuentas-bancarias/{cuentaBancaria}', [CuentaBancariaController::class, 'destroy'])->name('cuentas-bancarias.destroy');
    Route::post('cuentas-bancarias/{cuentaBancaria}/movimiento', [CuentaBancariaController::class, 'movimiento'])->name('cuentas-bancarias.movimiento');
    Route::get('cuentas-bancarias/{cuentaBancaria}/movimientos', [CuentaBancariaController::class, 'movimientos'])->name('cuentas-bancarias.movimientos');
    Route::post('cuentas-bancarias/{cuentaBancaria}/conciliar', [CuentaBancariaController::class, 'conciliar'])->name('cuentas-bancarias.conciliar');
    Route::get('cuentas-bancarias/{cuentaBancaria}/conciliaciones', [CuentaBancariaController::class, 'conciliaciones'])->name('cuentas-bancarias.conciliaciones');

    Route::apiResource('billetajes', BilletajeController::class)->only(['index', 'store']);
    Route::post('billetajes/{billetaje}/aprobar', [BilletajeController::class, 'aprobar'])->name('billetajes.aprobar');
    Route::post('billetajes/{billetaje}/rechazar', [BilletajeController::class, 'rechazar'])->name('billetajes.rechazar');

    Route::apiResource('conceptos', ConceptoController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('bienes', BienController::class)->only(['index', 'store', 'show', 'update'])->parameters(['bienes' => 'bien']);

    Route::apiResource('creditos-prendarios', CreditoPrendarioController::class)->only(['index', 'store', 'show'])->parameters(['creditos-prendarios' => 'credito']);
    Route::post('creditos-prendarios/{credito}/aprobar', [CreditoPrendarioController::class, 'aprobar'])->name('creditos-prendarios.aprobar');
    Route::post('creditos-prendarios/{credito}/rechazar', [CreditoPrendarioController::class, 'rechazar'])->name('creditos-prendarios.rechazar');
    Route::post('creditos-prendarios/{credito}/subsanar', [CreditoPrendarioController::class, 'subsanar'])->name('creditos-prendarios.subsanar');
    Route::post('creditos-prendarios/{credito}/desembolsar', [CreditoPrendarioController::class, 'desembolsar'])->name('creditos-prendarios.desembolsar');
    Route::post('creditos-prendarios/{credito}/refrendar', [CreditoPrendarioController::class, 'refrendar'])->name('creditos-prendarios.refrendar');
    Route::post('creditos-prendarios/{credito}/liquidar', [CreditoPrendarioController::class, 'liquidar'])->name('creditos-prendarios.liquidar');
    Route::post('creditos-prendarios/{credito}/actualizar-interes', [CreditoPrendarioController::class, 'actualizarInteres'])->name('creditos-prendarios.actualizar-interes');
    Route::post('creditos-prendarios/{credito}/revertir-aprobacion', [CreditoPrendarioController::class, 'revertirAprobacion'])->name('creditos-prendarios.revertir-aprobacion');
    Route::post('creditos-prendarios/{credito}/enviar-tienda', [CreditoPrendarioController::class, 'enviarATienda'])->name('creditos-prendarios.enviar-tienda');
    Route::get('creditos-prendarios/{credito}/cronograma/ver', [CreditoPrendarioController::class, 'verCronograma'])->name('creditos-prendarios.cronograma.ver');
    Route::get('creditos-prendarios/{credito}/documentos/{documento}/ver', [CreditoPrendarioController::class, 'verDocumento'])->name('creditos-prendarios.documentos.ver');
    Route::post('creditos-prendarios/{credito}/documentos/{documento}/marcar-impreso', [CreditoPrendarioController::class, 'marcarImpreso'])->name('creditos-prendarios.documentos.marcar-impreso');
    Route::post('creditos-prendarios/{credito}/documentos/{documento}/subir-firmado', [CreditoPrendarioController::class, 'subirDocumentoFirmado'])->name('creditos-prendarios.documentos.subir-firmado');

    Route::get('configuraciones-credito-prendario', [ConfiguracionCreditoPrendarioController::class, 'index'])->name('configuraciones-credito-prendario.index');
    Route::put('configuraciones-credito-prendario', [ConfiguracionCreditoPrendarioController::class, 'update'])->name('configuraciones-credito-prendario.update');
});

// ===== HEALTH CHECK =====
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
