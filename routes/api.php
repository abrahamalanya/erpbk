<?php

use App\Modules\Cliente\Http\Controllers\ClienteController;
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
});

// ===== HEALTH CHECK =====
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
