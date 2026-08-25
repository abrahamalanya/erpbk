<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Reportes\Services\ReporteMovimientosService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReporteMovimientosController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteMovimientosService $reporteMovimientosService) {}

    /**
     * Reporte de movimientos de dinero (efectivo y cuentas bancarias) de las
     * bóvedas visibles para el actor. Misma autoridad que "ver bóvedas" —
     * quien puede ver las bóvedas puede ver cómo se movió su dinero.
     */
    public function movimientosDinero(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Boveda::class);

        $bovedaId = $request->query('boveda_id');

        $reporte = $this->reporteMovimientosService->movimientosDinero(
            $request->user(),
            $request->query('desde'),
            $request->query('hasta'),
            $request->query('medio'),
            $bovedaId !== null ? (int) $bovedaId : null,
        );

        return $this->successResponse($reporte);
    }
}
