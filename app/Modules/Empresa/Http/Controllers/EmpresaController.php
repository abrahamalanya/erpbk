<?php

namespace App\Modules\Empresa\Http\Controllers;

use App\Modules\Empresa\Http\Requests\StoreEmpresaRequest;
use App\Modules\Empresa\Http\Requests\UpdateEmpresaRequest;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

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

        $data = $request->safe()->except(['logo', 'firma']);
        $empresa = Empresa::query()->create($data);

        $this->storeImagenes($request, $empresa);

        return $this->successResponse($empresa->fresh(), 'Empresa creada', 201);
    }

    public function show(Empresa $empresa): JsonResponse
    {
        Gate::authorize('view', $empresa);

        return $this->successResponse($empresa);
    }

    public function update(UpdateEmpresaRequest $request, Empresa $empresa): JsonResponse
    {
        Gate::authorize('update', $empresa);

        $data = $request->safe()->except(['logo', 'firma']);
        $empresa->update($data);

        $this->storeImagenes($request, $empresa);

        return $this->successResponse($empresa->fresh(), 'Empresa actualizada');
    }

    public function destroy(Empresa $empresa): JsonResponse
    {
        Gate::authorize('delete', $empresa);

        $empresa->delete();

        return $this->successResponse(null, 'Empresa eliminada');
    }

    private function storeImagenes(StoreEmpresaRequest|UpdateEmpresaRequest $request, Empresa $empresa): void
    {
        foreach (['logo', 'firma'] as $campo) {
            if (! $request->hasFile($campo)) {
                continue;
            }

            $column = "{$campo}_path";

            if ($empresa->{$column}) {
                Storage::disk('public')->delete($empresa->{$column});
            }

            $empresa->update([$column => $request->file($campo)->store("empresas/{$empresa->id}", 'public')]);
        }
    }
}
