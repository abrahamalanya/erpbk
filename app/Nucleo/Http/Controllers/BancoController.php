<?php

namespace App\Nucleo\Http\Controllers;

use App\Nucleo\Http\Requests\StoreBancoRequest;
use App\Nucleo\Http\Requests\UpdateBancoRequest;
use App\Nucleo\Models\Banco;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BancoController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Banco::class);

        $bancos = Banco::query()->orderBy('nombre')->get();

        return $this->successResponse($bancos);
    }

    public function store(StoreBancoRequest $request): JsonResponse
    {
        Gate::authorize('create', Banco::class);

        $banco = Banco::query()->create([...$request->validated(), 'activo' => $request->validated('activo', true)]);

        return $this->successResponse($banco, 'Banco creado', 201);
    }

    public function show(Banco $banco): JsonResponse
    {
        Gate::authorize('view', $banco);

        return $this->successResponse($banco);
    }

    public function update(UpdateBancoRequest $request, Banco $banco): JsonResponse
    {
        Gate::authorize('update', $banco);

        $banco->update($request->validated());

        return $this->successResponse($banco->fresh(), 'Banco actualizado');
    }

    public function destroy(Banco $banco): JsonResponse
    {
        Gate::authorize('delete', $banco);

        $banco->delete();

        return $this->successResponse(null, 'Banco eliminado');
    }
}
