<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Modules\Caja\Http\Requests\RechazarBilletajeRequest;
use App\Modules\Caja\Http\Requests\StoreBilletajeRequest;
use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Services\BilletajeService;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BilletajeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BilletajeService $billetajeService,
        private readonly CajaBovedaHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Billetaje::class);

        $query = Billetaje::query()->with(['boveda', 'solicitadoPor', 'aprobadoPor']);
        $query = $this->hierarchy->billetajesVisibles($query, request()->user());

        return $this->successResponse($query->latest()->paginate(15));
    }

    public function store(StoreBilletajeRequest $request): JsonResponse
    {
        Gate::authorize('create', Billetaje::class);

        $billetaje = $this->billetajeService->solicitar($request->user(), (string) $request->validated('monto'));

        return $this->successResponse($billetaje, 'Billetaje solicitado', 201);
    }

    public function aprobar(Billetaje $billetaje): JsonResponse
    {
        Gate::authorize('aprobar', $billetaje);

        $billetaje = $this->billetajeService->aprobar($billetaje, request()->user());

        return $this->successResponse($billetaje, 'Billetaje aprobado');
    }

    public function rechazar(RechazarBilletajeRequest $request, Billetaje $billetaje): JsonResponse
    {
        Gate::authorize('rechazar', $billetaje);

        $billetaje = $this->billetajeService->rechazar($billetaje, $request->user(), $request->validated('motivo'));

        return $this->successResponse($billetaje, 'Billetaje rechazado');
    }
}
