<?php

namespace App\Modules\CreditoDiario\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoDiario\Http\Requests\StoreCreditoDiarioRequest;
use App\Modules\CreditoDiario\Models\CreditoDiarioGarantia;
use App\Modules\Sistemas\Services\ModuloService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Solo cubre el registro de un crédito diario — todo su ciclo posterior
 * (aprobar, desembolsar, refrendar, adendar, liquidar) se atiende por los
 * endpoints compartidos de CreditoController, ya que el motor es el mismo.
 *
 * A diferencia de prendario/vehicular/hipotecario, diario no tiene una
 * garantía real que el cliente aporte: se crea una garantía placeholder
 * invisible (CreditoDiarioGarantia) solo para satisfacer el motor
 * compartido, que asume que todo crédito tiene al menos una garantía.
 */
class CreditoDiarioController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CreditoService $creditoService,
        private readonly ModuloService $modulos,
    ) {}

    public function store(StoreCreditoDiarioRequest $request): JsonResponse
    {
        Gate::authorize('creditos_diarios.crear');
        $this->modulos->autorizarCreacion($request->user(), 'diario');

        $data = $request->validated();

        if (($data['interes'] ?? null) !== null && (! ($data['interes_solicitud_especial'] ?? false) || $request->user()->hasRole('asesor'))) {
            Gate::authorize('creditos_prendarios.editar');
        }

        $cliente = Cliente::query()->findOrFail($data['cliente_id']);

        $garantia = CreditoDiarioGarantia::query()->create([
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'cliente_id' => $cliente->id,
            'registrado_por' => $request->user()->id,
            'valorizacion' => $data['monto_prestamo'],
        ]);

        $credito = $this->creditoService->registrar(
            $request->user(),
            collect([$garantia]),
            $data,
            'diario',
        );

        return $this->successResponse($credito->load(['cliente']), 'Crédito diario registrado', 201);
    }
}
