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

        return $this->successResponse(Agencia::query()->with('empresa')->paginate(15));
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

        $motivos = $this->motivosNoEliminable($agencia);

        if ($motivos !== []) {
            return $this->errorResponse(
                'No se puede eliminar la agencia porque tiene registros asociados: '.implode(', ', $motivos).'.',
                422
            );
        }

        $agencia->delete();

        return $this->successResponse(null, 'Agencia eliminada');
    }

    /**
     * @return list<string>
     */
    private function motivosNoEliminable(Agencia $agencia): array
    {
        $checks = [
            'boveda' => 'bóveda',
            'cajas' => 'cajas',
            'clientes' => 'clientes',
            'bienes' => 'bienes',
            'creditosPrendarios' => 'créditos prendarios',
            'configuracionesCreditoPrendario' => 'configuraciones de crédito prendario',
            'intereses' => 'intereses de la tienda',
        ];

        $motivos = [];

        foreach ($checks as $relation => $etiqueta) {
            if ($agencia->{$relation}()->exists()) {
                $motivos[] = $etiqueta;
            }
        }

        return $motivos;
    }
}
