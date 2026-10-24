<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Services\ClienteHierarchyService;
use App\Modules\Dashboard\Http\Requests\DashboardInicialRequest;
use App\Modules\Dashboard\Http\Requests\DashboardProduccionRequest;
use App\Modules\Dashboard\Services\DashboardAtrasosService;
use App\Modules\Dashboard\Services\DashboardService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
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
     * Cobranza vs. desembolso del día agrupados (por agencia, asesor, tipo de
     * crédito, o agencia y tipo a la vez) para el gráfico comparativo del
     * panel inicial. Mismos filtros y mismo alcance de rol que resumen().
     */
    public function graficoCobranzaDesembolso(DashboardInicialRequest $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor->can('dashboard.ver'), 403);

        return $this->successResponse($this->dashboard->graficoCobranzaDesembolso(
            $actor,
            $request->agenciaId(),
            $request->asesorId(),
            $request->tipoCredito(),
            $request->agruparPor(),
        ));
    }

    /**
     * Composición de la cartera activa (créditos activo + vencido) por tipo
     * de crédito, para el widget de concentración de riesgo del panel
     * inicial. Mismos filtros de agencia/asesor/tipo que el resto del
     * dashboard; el filtro de asesor mira cliente.asesor_id, no quién
     * registró la operación (ver DashboardService::creditosDeCartera()).
     */
    public function carteraActivaPorTipo(DashboardInicialRequest $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor->can('dashboard.ver'), 403);

        return $this->successResponse($this->dashboard->carteraActivaPorTipo(
            $actor,
            $request->agenciaId(),
            $request->asesorId(),
            $request->tipoCredito(),
        ));
    }

    /**
     * Créditos generados (= efectivamente desembolsados) por asesor y tipo
     * de crédito, en un período — para el gráfico de producción del panel
     * inicial. A diferencia del resto de Inicio, lleva su propio filtro de
     * fecha (desde/hasta, default mes en curso); agencia/asesor/tipo son los
     * mismos del resto del dashboard.
     */
    public function produccionPorAsesor(DashboardProduccionRequest $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor->can('dashboard.ver'), 403);

        return $this->successResponse($this->dashboard->produccionPorAsesor(
            $actor,
            $request->desde(),
            $request->hasta(),
            $request->agenciaId(),
            $request->asesorId(),
            $request->tipoCredito(),
        ));
    }

    /**
     * Avance de cobranza de HOY por asesor (programado/cobrado/pendiente/%),
     * reutilizando el mismo criterio de propiedad de cartera que la Ruta de
     * Cobranza (cliente.asesor_id). Filtros de agencia/asesor como el resto
     * del dashboard; no lleva tipo de crédito ni período, siempre es hoy.
     */
    public function avanceCobranzaHoy(DashboardInicialRequest $request): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor->can('dashboard.ver'), 403);

        return $this->successResponse($this->dashboard->avanceCobranzaHoy(
            $actor,
            $request->agenciaId(),
            $request->asesorId(),
        ));
    }

    /**
     * Clientes con su lugar de cobranza georreferenciado (casa o negocio,
     * según `lugar_cobranza`), para el mapa del panel principal — mismo
     * alcance de visibilidad que el listado de clientes (clientes.ver +
     * ClienteHierarchyService::visibleQuery). Un cliente cuyo lugar elegido
     * no tiene coordenadas queda fuera del mapa: nunca cae a la otra
     * ubicación. Cada uno lleva si tiene o no un crédito activo/vencido y,
     * de tenerlo, la fecha de vencimiento del más urgente (el de
     * vencimiento más próximo), para el popup del marcador.
     */
    public function mapaClientes(): JsonResponse
    {
        Gate::authorize('viewAny', Cliente::class);

        $query = Cliente::query()
            ->where('estado', 'activo')
            ->where(function (Builder $q): void {
                $q->where(fn (Builder $qq) => $qq->where('lugar_cobranza', 'negocio')->whereNotNull('latitud_negocio')->whereNotNull('longitud_negocio'))
                    ->orWhere(fn (Builder $qq) => $qq->where('lugar_cobranza', '!=', 'negocio')->whereNotNull('latitud')->whereNotNull('longitud'));
            })
            ->with(['creditos' => fn ($q) => $q->whereIn('estado', ['activo', 'vencido'])->orderBy('fecha_vencimiento')]);

        $query = $this->hierarchy->visibleQuery($query, request()->user());

        $clientes = $query->get()->map(function (Cliente $cliente): array {
            $creditoActivo = $cliente->creditos->first();
            $ubicacionCobranza = $cliente->ubicacionCobranza();

            return [
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'apellido' => $cliente->apellido,
                'tipo_documento' => $cliente->tipo_documento,
                'numero_documento' => $cliente->numero_documento,
                'direccion' => $ubicacionCobranza['direccion'],
                'referencia' => $ubicacionCobranza['referencia'],
                'latitud' => $ubicacionCobranza['latitud'],
                'longitud' => $ubicacionCobranza['longitud'],
                'lugar_cobranza' => $cliente->lugar_cobranza,
                'tiene_credito_activo' => $creditoActivo !== null,
                'fecha_vencimiento_credito' => $creditoActivo?->fecha_vencimiento?->toDateString(),
            ];
        });

        return $this->successResponse($clientes->values());
    }
}
