<?php

namespace App\Modules\Tienda\Http\Controllers;

use App\Modules\Sistemas\Services\NotificacionService;
use App\Modules\Tienda\Http\Requests\StoreInteresRequest;
use App\Modules\Tienda\Notifications\InteresArticuloNotification;
use App\Modules\Tienda\Services\TiendaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unified public storefront over every garantía en venta (bienes,
 * vehículos, …). No auth. `?tipo=bien|vehiculo` narrows the listing; the
 * detail/interés routes carry {tipo} in the path.
 */
class TiendaArticuloController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly NotificacionService $notificaciones,
        private readonly TiendaService $tienda,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tipo = $request->filled('tipo') ? $request->string('tipo')->value() : null;

        $articulos = $this->tienda->listar(
            $tipo,
            $request->filled('empresa_id') ? $request->integer('empresa_id') : null,
            $request->filled('agencia_id') ? $request->integer('agencia_id') : null,
            perPage: 12,
            page: max(1, $request->integer('page', 1)),
        );

        return $this->successResponse($articulos);
    }

    public function show(string $tipo, int $id): JsonResponse
    {
        $articulo = $this->tienda->resolver($tipo, $id);

        abort_if($articulo === null, 404);

        return $this->successResponse($this->tienda->datosPublicos($articulo));
    }

    public function interes(StoreInteresRequest $request, string $tipo, int $id): JsonResponse
    {
        $articulo = $this->tienda->resolver($tipo, $id);

        abort_if($articulo === null, 404);

        $interes = $this->tienda->registrarInteres($articulo, $request->validated());

        $this->notificaciones->enviar($this->tienda->controladoresDe($articulo), new InteresArticuloNotification($interes));

        return $this->successResponse(null, 'Gracias, en breve te contactaremos.', 201);
    }
}
