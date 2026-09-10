<?php

namespace App\Modules\Cobranza\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Credito\Services\CreditoService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Módulo Cobranzas: un listado transversal de todos los cobros y un punto
 * de entrada para registrarlos. El pago en sí se ejecuta con los endpoints
 * ya existentes del crédito (POST /creditos-prendarios/{credito}/refrendar
 * y /liquidar); aquí solo se listan los cobros y se resuelven los créditos
 * pendientes de un cliente con sus montos a pagar.
 */
class CobroController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CreditoHierarchyService $creditoHierarchy,
        private readonly CreditoService $creditoService,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Cobro::class);

        $actor = request()->user();

        $query = Cobro::query()
            ->with(['cliente', 'credito', 'registradoPor'])
            ->whereHas('credito', fn (Builder $q) => $this->creditoHierarchy->visibleQuery($q, $actor));

        if (request()->filled('q')) {
            $termino = trim((string) request()->string('q'));
            $query->whereHas('cliente', function (Builder $sub) use ($termino): void {
                $sub->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('apellido', 'like', "%{$termino}%")
                    ->orWhere('numero_documento', 'like', "%{$termino}%");
            });
        }

        if (request()->filled('operacion')) {
            $query->where('operacion', (string) request()->string('operacion'));
        }

        if (request()->filled('desde')) {
            $query->whereDate('created_at', '>=', request()->date('desde'));
        }

        if (request()->filled('hasta')) {
            $query->whereDate('created_at', '<=', request()->date('hasta'));
        }

        $porPagina = max(1, min(request()->integer('per_page', 15), 100));

        return $this->successResponse($query->latest()->paginate($porPagina));
    }

    /**
     * Créditos con deuda vigente (activo / vencido) del cliente, visibles
     * para el actor. Cada uno trae `monto_refrendo_sugerido` (solo interés) y
     * `monto_liquidacion_sugerido` (capital + interés + mora), calculados
     * igual que en CreditoController::show(), para prellenar el formulario de
     * cobro.
     */
    public function creditosPendientes(Cliente $cliente): JsonResponse
    {
        Gate::authorize('registrar', Cobro::class);

        $creditos = $this->creditoHierarchy
            ->visibleQuery(Credito::query(), request()->user())
            ->where('cliente_id', $cliente->id)
            ->whereIn('estado', ['activo', 'vencido'])
            ->with(['bienes', 'vehiculos', 'inmuebles', 'agencia'])
            ->latest()
            ->get()
            ->each(function (Credito $credito): void {
                $credito->setAttribute('monto_liquidacion_sugerido', $this->creditoService->calcularMontoLiquidacion($credito));
                $credito->setAttribute('monto_refrendo_sugerido', $this->creditoService->calcularMontoRefrendo($credito));
            });

        return $this->successResponse($creditos);
    }
}
