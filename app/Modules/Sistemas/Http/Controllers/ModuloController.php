<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Http\Requests\SaveModuloRequest;
use App\Modules\Sistemas\Models\Modulo;
use App\Modules\Sistemas\Services\ModuloService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ModuloController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ModuloService $modulos) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Modulo::class);

        return $this->successResponse(Modulo::query()->orderBy('grupo')->orderBy('nombre')->get());
    }

    public function show(Modulo $modulo): JsonResponse
    {
        Gate::authorize('manage', Modulo::class);

        return $this->successResponse([...$modulo->toArray(), 'uso' => $this->modulos->usos($modulo)]);
    }

    public function store(SaveModuloRequest $request): JsonResponse
    {
        Gate::authorize('manage', Modulo::class);

        return $this->successResponse(Modulo::query()->create($request->safe()->only(['key', 'nombre', 'grupo'])), 'Módulo registrado', 201);
    }

    public function update(SaveModuloRequest $request, Modulo $modulo): JsonResponse
    {
        Gate::authorize('manage', Modulo::class);
        $actual = DB::transaction(function () use ($request, $modulo): Modulo {
            $actual = Modulo::query()->lockForUpdate()->findOrFail($modulo->id);
            $this->modulos->verificarRevision($actual, $request->validated('revision'));
            $actual->update($request->safe()->only(['nombre', 'grupo']));

            return $actual;
        });

        return $this->successResponse($actual, 'Módulo actualizado');
    }

    public function destroy(SaveModuloRequest $request, Modulo $modulo): JsonResponse
    {
        Gate::authorize('manage', Modulo::class);
        $this->modulos->eliminar($modulo, $request->validated('revision'));

        return $this->successResponse(null, 'Módulo eliminado');
    }
}
