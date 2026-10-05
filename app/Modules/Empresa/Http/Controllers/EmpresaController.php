<?php

namespace App\Modules\Empresa\Http\Controllers;

use App\Modules\Empresa\Http\Requests\StoreEmpresaRequest;
use App\Modules\Empresa\Http\Requests\UpdateEmpresaRequest;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\GestionaImagenes;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class EmpresaController extends Controller
{
    use ApiResponse, GestionaImagenes;

    /**
     * Campos de imagen del recurso: cada uno se persiste en su columna
     * `{campo}_path` dentro de la carpeta `empresas`.
     *
     * @var list<string>
     */
    private const IMAGENES = ['logo', 'firma'];

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Empresa::class);

        return $this->successResponse(Empresa::query()->paginate(15));
    }

    public function store(StoreEmpresaRequest $request): JsonResponse
    {
        Gate::authorize('create', Empresa::class);

        $data = $request->safe()->except(self::IMAGENES);
        $empresa = Empresa::query()->create($data);

        $this->storeImagenes($request, $empresa, self::IMAGENES, 'empresas');

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

        $data = $request->safe()->except(self::IMAGENES);
        $empresa->update($data);

        $this->storeImagenes($request, $empresa, self::IMAGENES, 'empresas');

        return $this->successResponse($empresa->fresh(), 'Empresa actualizada');
    }

    public function destroy(Empresa $empresa): JsonResponse
    {
        Gate::authorize('delete', $empresa);

        $this->eliminarImagenes($empresa, self::IMAGENES);
        $empresa->delete();

        return $this->successResponse(null, 'Empresa eliminada');
    }
}
