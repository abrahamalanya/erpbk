<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAgenciaRequest;
use App\Http\Requests\UpdateAgenciaRequest;
use App\Models\Agencia;
use App\Traits\ApiResponse;
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
