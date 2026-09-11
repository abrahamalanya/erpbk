<?php

namespace App\Modules\Simulador\Http\Controllers;

use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Simulador\Http\Requests\StoreSimulacionCreditoRequest;
use App\Modules\Simulador\Models\SimulacionCredito;
use App\Modules\Simulador\Services\SimuladorService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SimuladorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SimuladorService $simuladorService,
        private readonly CreditoHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', SimulacionCredito::class);

        $query = SimulacionCredito::query()->with(['cliente', 'registradoPor', 'agencia']);
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        if ($clienteId = request()->integer('cliente_id')) {
            $query->where('cliente_id', $clienteId);
        }

        return $this->successResponse($query->latest()->paginate(15));
    }

    public function store(StoreSimulacionCreditoRequest $request): JsonResponse
    {
        Gate::authorize('create', SimulacionCredito::class);

        $simulacion = $this->simuladorService->simular($request->user(), $request->validated());

        return $this->successResponse($simulacion->load(['cliente', 'registradoPor']), 'Simulación registrada', 201);
    }

    public function show(SimulacionCredito $simulacion): JsonResponse
    {
        Gate::authorize('view', $simulacion);

        return $this->successResponse($simulacion->load(['cliente', 'registradoPor', 'agencia']));
    }

    public function destroy(SimulacionCredito $simulacion): JsonResponse
    {
        Gate::authorize('delete', $simulacion);

        $simulacion->delete();

        return $this->successResponse(null, 'Simulación eliminada');
    }
}
