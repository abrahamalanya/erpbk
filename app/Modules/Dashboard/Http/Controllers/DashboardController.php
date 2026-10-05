<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Services\ClienteHierarchyService;
use App\Modules\Dashboard\Http\Requests\DashboardInicialRequest;
use App\Modules\Dashboard\Services\DashboardAtrasosService;
use App\Modules\Dashboard\Services\DashboardService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClienteHierarchyService $hierarchy,
        private readonly DashboardService $dashboard,
        private readonly DashboardAtrasosService $atrasos,
    ) {}

    /**
     * Totales del día para el panel inicial: cobranzas (cobros + pagos de
     * ventas) y desembolsos, con el neto de la diferencia. Filtros opcionales
     * por agencia, asesor (quien cobró) y tipo de crédito; el alcance siempre
     * se acota a lo que el actor puede ver, así que un filtro no amplía nada.
     */
    public function resumen(DashboardInicialRequest $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor->can('dashboard.ver'), 403);

        return $this->successResponse($this->dashboard->resumenDelDia(
            $actor,
            $request->agenciaId(),
            $request->asesorId(),
            $request->tipoCredito(),
        ));
    }

    /**
     * Las dos líneas de tiempo del panel inicial: cartera en mora del reporte
     * de atrasos-diarios, repartida en las franjas de riesgo (verde / amarillo /
     * rojo / sin color), en cantidad de créditos y en dinero por cobrar, con el
     * porcentaje cobrado hoy de cada franja.
     *
     * Comparte los filtros y el alcance de rol del resumen de arriba: lo que
     * este endpoint cuenta es un subconjunto de la cartera, nunca más.
     */
    public function lineaDeTiempoAtrasos(DashboardInicialRequest $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor->can('dashboard.ver'), 403);

        return $this->successResponse($this->atrasos->lineaDeTiempo(
            $actor,
            $request->agenciaId(),
            $request->asesorId(),
            $request->tipoCredito(),
        ));
    }

    /**
     * Clientes con dirección georreferenciada (casa), para el mapa del panel
     * principal — mismo alcance de visibilidad que el listado de clientes
     * (clientes.ver + ClienteHierarchyService::visibleQuery). Cada uno lleva
     * si tiene o no un crédito activo/vencido y, de tenerlo, la fecha de
     * vencimiento del más urgente (el de vencimiento más próximo), para el
     * popup del marcador.
     */
    public function mapaClientes(): JsonResponse
    {
        Gate::authorize('viewAny', Cliente::class);

        $query = Cliente::query()
            ->where('estado', 'activo')
            ->whereNotNull('latitud')
            ->whereNotNull('longitud')
            ->with(['creditos' => fn ($q) => $q->whereIn('estado', ['activo', 'vencido'])->orderBy('fecha_vencimiento')]);

        $query = $this->hierarchy->visibleQuery($query, request()->user());

        $clientes = $query->get()->map(function (Cliente $cliente): array {
            $creditoActivo = $cliente->creditos->first();

            return [
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'apellido' => $cliente->apellido,
                'tipo_documento' => $cliente->tipo_documento,
                'numero_documento' => $cliente->numero_documento,
                'direccion' => $cliente->direccion,
                'referencia' => $cliente->referencia,
                'latitud' => (float) $cliente->latitud,
                'longitud' => (float) $cliente->longitud,
                'tiene_credito_activo' => $creditoActivo !== null,
                'fecha_vencimiento_credito' => $creditoActivo?->fecha_vencimiento?->toDateString(),
            ];
        });

        return $this->successResponse($clientes->values());
    }
}
