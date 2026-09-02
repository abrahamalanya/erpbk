<?php

namespace App\Modules\CreditoVehicular\Http\Controllers;

use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoVehicular\Http\Requests\StoreCreditoVehicularRequest;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Solo cubre el registro de un crédito vehicular — todo su ciclo posterior
 * (aprobar, desembolsar, refrendar, adendar, liquidar, conformidad, enviar
 * a tienda, documentos) se atiende por los endpoints compartidos de
 * CreditoController, ya que el motor es el mismo.
 */
class CreditoVehicularController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CreditoService $creditoService,
    ) {}

    public function store(StoreCreditoVehicularRequest $request): JsonResponse
    {
        Gate::authorize('creditos_vehiculares.crear');

        $data = $request->validated();

        if (($data['interes'] ?? null) !== null) {
            Gate::authorize('creditos_prendarios.editar');
        }

        $vehiculos = Vehiculo::query()->whereIn('id', $data['vehiculo_ids'])->get();

        $credito = $this->creditoService->registrar($request->user(), $vehiculos, $data, 'vehicular');

        return $this->successResponse($credito->load(['vehiculos', 'cliente', 'supervisadoPor']), 'Crédito vehicular registrado', 201);
    }
}
