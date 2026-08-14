<?php

use App\Http\Controllers\Api\AgenciaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmpresaController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
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
});

// ===== HEALTH CHECK =====
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
