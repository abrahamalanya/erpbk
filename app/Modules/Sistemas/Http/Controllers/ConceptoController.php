<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Http\Requests\StoreConceptoRequest;
use App\Modules\Sistemas\Http\Requests\UpdateConceptoRequest;
use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Sistemas\Services\ConceptoService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ConceptoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConceptoService $conceptoService) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Concepto::class);

        $query = Concepto::query()->with('empresa')->orderBy('nombre');

        // Only meaningful for sistemas: TenantScope leaves its queries
        // unfiltered (it can see every empresa's catalog), so the frontend
        // needs an explicit filter to browse one empresa at a time. A
        // regular tenant user's own empresa is already the only one
        // reachable, so this filter is a no-op for them either way.
        if (request()->filled('empresa_id')) {
            $query->where('empresa_id', request()->integer('empresa_id'));
        }

        if (request()->filled('tipo')) {
            $query->where('tipo', request()->string('tipo'));
        }

        if (! request()->boolean('con_inactivos')) {
            $query->where('activo', true);
        }

        return $this->successResponse($query->get());
    }

    public function store(StoreConceptoRequest $request): JsonResponse
    {
        Gate::authorize('create', Concepto::class);

        $concepto = $this->conceptoService->crear($request->user(), $request->validated());

        return $this->successResponse($concepto, 'Concepto creado', 201);
    }

    public function update(UpdateConceptoRequest $request, Concepto $concepto): JsonResponse
    {
        Gate::authorize('update', $concepto);

        $concepto = $this->conceptoService->actualizar($concepto, $request->validated());

        return $this->successResponse($concepto, 'Concepto actualizado');
    }

    public function destroy(Concepto $concepto): JsonResponse
    {
        Gate::authorize('delete', $concepto);

        $this->conceptoService->eliminar($concepto);

        return $this->successResponse(null, 'Concepto eliminado');
    }
}
