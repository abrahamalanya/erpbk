<?php

use App\Modules\Caja\Http\Controllers\BilletajeController;
use App\Modules\Caja\Http\Controllers\BovedaController;
use App\Modules\Caja\Http\Controllers\CajaController;
use App\Modules\Cliente\Http\Controllers\ClienteController;
use App\Modules\CreditoPrendario\Http\Controllers\BienController;
use App\Modules\CreditoPrendario\Http\Controllers\ConfiguracionCreditoPrendarioController;
use App\Modules\CreditoPrendario\Http\Controllers\CreditoPrendarioController;
use App\Modules\Empresa\Http\Controllers\AgenciaController;
use App\Modules\Empresa\Http\Controllers\EmpresaController;
use App\Modules\Sistemas\Http\Controllers\AuthController;
use App\Modules\Sistemas\Http\Controllers\PermissionController;
use App\Modules\Sistemas\Http\Controllers\RoleController;
use App\Modules\Usuario\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// ===== AUTH ROUTES (públicas) =====
Route::post('/auth/login', [AuthController::class, 'login']);

// ===== PROTECTED ROUTES (requieren autenticación) =====
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::apiResource('empresas', EmpresaController::class);
    Route::apiResource('agencias', AgenciaController::class);
    Route::apiResource('usuarios', UserController::class)->parameters(['usuarios' => 'user']);
    Route::apiResource('roles', RoleController::class)->only(['index', 'show', 'update']);
    Route::apiResource('permisos', PermissionController::class)->only(['index']);

    Route::apiResource('clientes', ClienteController::class);
    Route::post('clientes/{cliente}/asignar', [ClienteController::class, 'asignar'])->name('clientes.asignar');
    Route::get('clientes/consultar-dni/{dni}', [ClienteController::class, 'consultarDni'])->name('clientes.consultar-dni');

    Route::get('caja', [CajaController::class, 'miCaja'])->name('caja.mia');
    Route::post('caja/aperturar', [CajaController::class, 'aperturar'])->name('caja.aperturar');
    Route::post('caja/cerrar', [CajaController::class, 'cerrar'])->name('caja.cerrar');
    Route::apiResource('cajas', CajaController::class)->only(['index', 'show']);
    Route::post('cajas/{caja}/cerrar-forzado', [CajaController::class, 'cerrarForzado'])->name('cajas.cerrar-forzado');

    Route::apiResource('bovedas', BovedaController::class)->only(['index', 'show']);
    Route::post('bovedas/{boveda}/cerrar', [BovedaController::class, 'cerrar'])->name('bovedas.cerrar');

    Route::apiResource('billetajes', BilletajeController::class)->only(['index', 'store']);
    Route::post('billetajes/{billetaje}/aprobar', [BilletajeController::class, 'aprobar'])->name('billetajes.aprobar');
    Route::post('billetajes/{billetaje}/rechazar', [BilletajeController::class, 'rechazar'])->name('billetajes.rechazar');

    Route::apiResource('bienes', BienController::class)->only(['index', 'store', 'show', 'update'])->parameters(['bienes' => 'bien']);

    Route::apiResource('creditos-prendarios', CreditoPrendarioController::class)->only(['index', 'store', 'show'])->parameters(['creditos-prendarios' => 'credito']);
    Route::post('creditos-prendarios/{credito}/aprobar', [CreditoPrendarioController::class, 'aprobar'])->name('creditos-prendarios.aprobar');
    Route::post('creditos-prendarios/{credito}/rechazar', [CreditoPrendarioController::class, 'rechazar'])->name('creditos-prendarios.rechazar');
    Route::post('creditos-prendarios/{credito}/firmar', [CreditoPrendarioController::class, 'firmar'])->name('creditos-prendarios.firmar');
    Route::post('creditos-prendarios/{credito}/refrendar', [CreditoPrendarioController::class, 'refrendar'])->name('creditos-prendarios.refrendar');
    Route::post('creditos-prendarios/{credito}/liquidar', [CreditoPrendarioController::class, 'liquidar'])->name('creditos-prendarios.liquidar');
    Route::get('creditos-prendarios/{credito}/documentos/{documento}/descargar', [CreditoPrendarioController::class, 'descargarDocumento'])->name('creditos-prendarios.documentos.descargar');
    Route::post('creditos-prendarios/{credito}/documentos/{documento}/marcar-impreso', [CreditoPrendarioController::class, 'marcarImpreso'])->name('creditos-prendarios.documentos.marcar-impreso');
    Route::post('creditos-prendarios/{credito}/documentos/{documento}/marcar-firmado', [CreditoPrendarioController::class, 'marcarFirmadoDocumento'])->name('creditos-prendarios.documentos.marcar-firmado');

    Route::get('configuraciones-credito-prendario', [ConfiguracionCreditoPrendarioController::class, 'index'])->name('configuraciones-credito-prendario.index');
    Route::put('configuraciones-credito-prendario', [ConfiguracionCreditoPrendarioController::class, 'update'])->name('configuraciones-credito-prendario.update');
});

// ===== HEALTH CHECK =====
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
