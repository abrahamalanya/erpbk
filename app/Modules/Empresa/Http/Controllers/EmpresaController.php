<?php

namespace App\Modules\Empresa\Http\Controllers;

use App\Modules\Empresa\Http\Requests\StoreEmpresaRequest;
use App\Modules\Empresa\Http\Requests\UpdateEmpresaRequest;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class EmpresaController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Empresa::class);

        return $this->successResponse(Empresa::query()->paginate(15));
    }

    public function store(StoreEmpresaRequest $request): JsonResponse
    {
        Gate::authorize('create', Empresa::class);

        $empresa = Empresa::query()->create($request->validated());

        return $this->successResponse($empresa, 'Empresa creada', 201);
    }

    public function show(Empresa $empresa): JsonResponse
    {
        Gate::authorize('view', $empresa);

        return $this->successResponse($empresa);
    }

    public function update(UpdateEmpresaRequest $request, Empresa $empresa): JsonResponse
    {
        Gate::authorize('update', $empresa);

        $empresa->update($request->validated());

        return $this->successResponse($empresa, 'Empresa actualizada');
    }

    public function destroy(Empresa $empresa): JsonResponse
    {
        Gate::authorize('delete', $empresa);

        $empresa->delete();

        return $this->successResponse(null, 'Empresa eliminada');
    }
}
