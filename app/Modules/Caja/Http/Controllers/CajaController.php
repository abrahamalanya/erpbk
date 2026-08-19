<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Modules\Caja\Http\Requests\StoreCajaCierreRequest;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Caja\Services\CajaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CajaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CajaService $cajaService,
        private readonly CajaBovedaHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        $query = Caja::query()->with(['user.roles', 'agencia', 'cicloAbierto']);
        $query = $this->hierarchy->cajasVisibles($query, request()->user());

        return $this->successResponse($query->paginate(15));
    }

    public function miCaja(): JsonResponse
    {
        $caja = $this->cajaService->cajaDe(request()->user());

        return $this->successResponse($caja->load(['cicloAbierto']));
    }

    public function show(Caja $caja): JsonResponse
    {
        Gate::authorize('view', $caja);

        return $this->successResponse($caja->load(['user', 'agencia', 'cicloAbierto']));
    }

    public function aperturar(): JsonResponse
    {
        Gate::authorize('aperturar', Caja::class);

        $ciclo = $this->cajaService->aperturar(request()->user());

        return $this->successResponse($ciclo, 'Caja aperturada', 201);
    }

    public function cerrar(StoreCajaCierreRequest $request): JsonResponse
    {
        Gate::authorize('cerrar', Caja::class);

        $ciclo = $this->cajaService->cerrar($request->user(), (string) $request->validated('monto_contado'));

        return $this->successResponse($ciclo, 'Caja cerrada');
    }

    public function cerrarForzado(StoreCajaCierreRequest $request, Caja $caja): JsonResponse
    {
        Gate::authorize('cerrarForzado', $caja);

        $ciclo = $this->cajaService->cerrarForzado($request->user(), $caja, (string) $request->validated('monto_contado'));

        return $this->successResponse($ciclo, 'Caja cerrada (forzado)');
    }

    public function reabrir(Caja $caja): JsonResponse
    {
        Gate::authorize('reabrir', $caja);

        $ciclo = $this->cajaService->reabrir($caja, request()->user());

        return $this->successResponse($ciclo, 'Caja reabierta');
    }
}
