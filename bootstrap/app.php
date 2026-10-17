<?php

use App\Nucleo\Exceptions\ConflictoDeEstadoException;
use App\Nucleo\Exceptions\MotivoConflicto;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Conflicto de estado: el servidor no aplica la operación porque su
        // estado real la contradice (caja cerrada/abierta, crédito liquidado…).
        // Va ANTES del manejador genérico de DomainException: como
        // ConflictoDeEstadoException extiende DomainException, el orden decide
        // cuál responde. El `reason` le dice a la app offline qué pasó.
        $exceptions->render(function (ConflictoDeEstadoException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'reason' => $e->reason,
                    'data' => null,
                ], 409);
            }
        });

        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                ], 422);
            }
        });
    })
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
        __DIR__.'/../app/Modules/Facturacion/Console/Commands',
    ])
    ->create();