<?php

namespace App\Modules\CreditoHipotecario\Http\Controllers;

use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoHipotecario\Http\Requests\StoreCreditoHipotecarioRequest;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Solo cubre el registro de un crédito hipotecario — todo su ciclo posterior
 * (aprobar, desembolsar, refrendar, adendar, liquidar, conformidad, enviar
 * a tienda, documentos) se atiende por los endpoints compartidos de
 * CreditoController, ya que el motor es el mismo.
 */
class CreditoHipotecarioController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CreditoService $creditoService,
    ) {}

    public function store(StoreCreditoHipotecarioRequest $request): JsonResponse
    {
        Gate::authorize('creditos_hipotecarios.crear');

        $data = $request->validated();

        if (($data['interes'] ?? null) !== null) {
            Gate::authorize('creditos_prendarios.editar');
        }

        $inmuebles = Inmueble::query()->whereIn('id', $data['inmueble_ids'])->get();

        $credito = $this->creditoService->registrar($request->user(), $inmuebles, $data, 'hipotecario');

        return $this->successResponse($credito->load(['inmuebles', 'cliente', 'supervisadoPor']), 'Crédito hipotecario registrado', 201);
    }
}
