<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Modules\Caja\Http\Requests\StoreCajaCierreRequest;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Services\BovedaService;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BovedaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BovedaService $bovedaService,
        private readonly CajaBovedaHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Boveda::class);

        $query = Boveda::query()->with(['empresa', 'agencia', 'cicloAbierto']);
        $query = $this->hierarchy->bovedasVisibles($query, request()->user());

        return $this->successResponse($query->paginate(15));
    }

    public function show(Boveda $boveda): JsonResponse
    {
        Gate::authorize('view', $boveda);

        return $this->successResponse($boveda->load(['empresa', 'agencia', 'cicloAbierto']));
    }

    public function cerrar(StoreCajaCierreRequest $request, Boveda $boveda): JsonResponse
    {
        Gate::authorize('cerrar', $boveda);

        $ciclo = $this->bovedaService->cerrar($boveda, $request->user(), (string) $request->validated('monto_contado'));

        return $this->successResponse($ciclo, 'Bóveda cerrada');
    }
}
