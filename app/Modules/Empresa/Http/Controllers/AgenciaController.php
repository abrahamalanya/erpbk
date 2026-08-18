<?php

namespace App\Modules\Empresa\Http\Controllers;

use App\Modules\Empresa\Http\Requests\StoreAgenciaRequest;
use App\Modules\Empresa\Http\Requests\UpdateAgenciaRequest;
use App\Modules\Empresa\Models\Agencia;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AgenciaController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Agencia::class);

        return $this->successResponse(Agencia::query()->paginate(15));
    }

    public function store(StoreAgenciaRequest $request): JsonResponse
    {
        Gate::authorize('create', Agencia::class);

        $data = $request->validated();
        $data['empresa_id'] = $request->user()->hasRole('sistemas')
            ? $data['empresa_id']
            : $request->user()->empresa_id;

        $agencia = Agencia::query()->create($data);

        return $this->successResponse($agencia, 'Agencia creada', 201);
    }

    public function show(Agencia $agencia): JsonResponse
    {
        Gate::authorize('view', $agencia);

        return $this->successResponse($agencia);
    }

    public function update(UpdateAgenciaRequest $request, Agencia $agencia): JsonResponse
    {
        Gate::authorize('update', $agencia);

        $agencia->update($request->validated());

        return $this->successResponse($agencia, 'Agencia actualizada');
    }

    public function destroy(Agencia $agencia): JsonResponse
    {
        Gate::authorize('delete', $agencia);

        $agencia->delete();

        return $this->successResponse(null, 'Agencia eliminada');
    }
}
