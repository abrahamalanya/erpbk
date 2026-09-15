<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Credito\Models\Credito;
use App\Modules\Reportes\Services\ReporteCobranzaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ReporteCobranzaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteCobranzaService $reporteCobranzaService) {}

    /**
     * Cobranza diaria: un cliente aparece una vez por cada crédito suyo con
     * una cuota vencida u hoy — misma autoridad que "ver créditos".
     */
    public function cobranzaDiaria(): JsonResponse
    {
        Gate::authorize('viewAny', Credito::class);

        return $this->successResponse($this->reporteCobranzaService->cobranzaDiaria(request()->user()));
    }
}
