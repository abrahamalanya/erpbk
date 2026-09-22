<?php

namespace App\Modules\Venta\Http\Controllers;

use App\Modules\Tienda\Services\TiendaService;
use App\Modules\Venta\Models\Venta;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Catálogo interno (autenticado) de artículos en tienda, para el flujo de
 * registrar una venta — el mismo catálogo se usa tanto si el operador entró
 * "buscando al cliente" como si entró "buscando el producto" (confirmado
 * con el usuario): ambos terminan eligiendo un artículo de esta lista.
 * Reutiliza TiendaService — no duplica la query pública.
 */
class CatalogoVentaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TiendaService $tienda) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('create', Venta::class);

        $actor = $request->user();
        $tipo = $request->filled('tipo') ? $request->string('tipo')->value() : null;
        $agenciaId = $actor->hasAnyRole(['administrador_agencia', 'asesor']) ? $actor->agencia_id : null;

        $articulos = $this->tienda->listar(
            $tipo,
            $actor->hasRole('sistemas') ? null : $actor->empresa_id,
            $agenciaId,
            perPage: 12,
            page: max(1, $request->integer('page', 1)),
        );

        return $this->successResponse($articulos);
    }
}
