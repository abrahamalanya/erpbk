<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Modules\Caja\Http\Requests\StoreBovedaAperturaRequest;
use App\Modules\Caja\Http\Requests\StoreBovedaInyeccionRequest;
use App\Modules\Caja\Http\Requests\StoreCajaCierreRequest;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Services\BovedaService;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Caja\Services\CuentaBancariaService;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BovedaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BovedaService $bovedaService,
        private readonly CajaBovedaHierarchyService $hierarchy,
        private readonly CuentaBancariaService $cuentaBancariaService,
    ) {}

    /**
     * The single bóveda this admin controls — administrador_general gets the
     * empresa's principal bóveda, administrador_agencia gets their own
     * agencia bóveda (bovedaFinanciadoraDe() happens to resolve exactly this
     * for admin roles, since their own caja is funded by the same bóveda
     * they control). Powers a header badge, same shape as GET /caja.
     */
    public function mia(): JsonResponse
    {
        Gate::authorize('viewAny', Boveda::class);

        $actor = request()->user();
        abort_unless($actor->hasAnyRole(['administrador_general', 'administrador_agencia']), 403);

        $boveda = $this->hierarchy->bovedaFinanciadoraDe($actor)->load('cicloAbierto');
        $this->adjuntarSaldoActual(new Collection([$boveda]));

        return $this->successResponse($boveda);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Boveda::class);

        $this->asegurarBovedaPropiaProvisionada(request()->user());

        $query = Boveda::query()->with(['empresa', 'agencia', 'cicloAbierto']);
        $query = $this->hierarchy->bovedasVisibles($query, request()->user());

        $bovedas = $query->paginate(15);
        $this->adjuntarSaldoActual($bovedas->getCollection());

        return $this->successResponse($bovedas);
    }

    public function show(Boveda $boveda): JsonResponse
    {
        Gate::authorize('view', $boveda);

        $boveda->load(['empresa', 'agencia', 'cicloAbierto']);
        $this->adjuntarSaldoActual(new Collection([$boveda]));

        return $this->successResponse($boveda);
    }

    public function cerrar(StoreCajaCierreRequest $request, Boveda $boveda): JsonResponse
    {
        Gate::authorize('cerrar', $boveda);

        $ciclo = $this->bovedaService->cerrar($boveda, $request->user(), (string) $request->validated('monto_contado'));

        return $this->successResponse($ciclo, 'Bóveda cerrada');
    }

    public function aperturar(StoreBovedaAperturaRequest $request, Boveda $boveda): JsonResponse
    {
        Gate::authorize('aperturar', $boveda);

        $ciclo = $this->bovedaService->aperturar($boveda, $request->user(), $request->validated('saldo_inicial'));

        return $this->successResponse($ciclo, 'Bóveda aperturada', 201);
    }

    public function inyectar(StoreBovedaInyeccionRequest $request, Boveda $boveda): JsonResponse
    {
        Gate::authorize('inyectar', $boveda);

        $cuentaBancariaId = $request->validated('cuenta_bancaria_id');
        $cuentaBancariaOrigenId = $request->validated('cuenta_bancaria_origen_id');

        $movimiento = $this->bovedaService->inyectar(
            $boveda,
            $request->user(),
            (string) $request->validated('monto'),
            $request->validated('concepto'),
            $request->validated('medio', 'efectivo'),
            $cuentaBancariaId !== null ? (int) $cuentaBancariaId : null,
            $cuentaBancariaOrigenId !== null ? (int) $cuentaBancariaOrigenId : null,
        );

        return $this->successResponse($movimiento, 'Capital inyectado', 201);
    }

    /**
     * Reporte de inyecciones/traspasos de esta bóveda (efectivo y cuenta
     * bancaria juntos), opcionalmente filtrado por fecha. Misma autoridad
     * que inyectar() — quien puede inyectar capital puede ver y deshacer
     * sus propias inyecciones.
     */
    public function inyecciones(Boveda $boveda): JsonResponse
    {
        Gate::authorize('inyectar', $boveda);

        $reporte = $this->bovedaService->reporteInyecciones(
            $boveda,
            request()->query('desde'),
            request()->query('hasta'),
        );

        return $this->successResponse($reporte);
    }

    public function eliminarInyeccion(Boveda $boveda, int $movimiento): JsonResponse
    {
        Gate::authorize('inyectar', $boveda);

        $this->bovedaService->eliminarInyeccion($boveda, $movimiento);

        return $this->successResponse(null, 'Inyección eliminada');
    }

    public function reabrir(Boveda $boveda): JsonResponse
    {
        Gate::authorize('reabrir', $boveda);

        $ciclo = $this->bovedaService->reabrir($boveda, request()->user());

        return $this->successResponse($ciclo, 'Bóveda reabierta');
    }

    /**
     * Bóveda rows are otherwise lazily created (firstOrCreate) only when a
     * caja underneath actually needs one — on a genuinely fresh empresa,
     * nothing would exist yet for administrador_general/administrador_agencia
     * to even see and act on. Provision the bóveda(s) this actor controls
     * before listing, so "aperturar"/"inyectar" have a row to target from
     * day one — including every agencia bóveda in the empresa, since
     * administrador_general can traspasar capital into any of them even
     * before that agencia ever opened a caja.
     */
    private function asegurarBovedaPropiaProvisionada(User $actor): void
    {
        if ($actor->hasAnyRole(['administrador_general', 'secretaria']) && $actor->empresa_id) {
            $this->bovedaService->principalDe($actor->empresa_id);

            Agencia::query()->where('empresa_id', $actor->empresa_id)->pluck('id')
                ->each(fn (int $agenciaId) => $this->bovedaService->deAgencia($agenciaId));
        }

        if ($actor->hasRole('administrador_agencia') && $actor->agencia_id) {
            $this->bovedaService->deAgencia($actor->agencia_id);
        }
    }

    /**
     * @param  Collection<int, Boveda>  $bovedas
     */
    private function adjuntarSaldoActual(Collection $bovedas): void
    {
        $bovedas->each(function (Boveda $boveda): void {
            if ($boveda->cicloAbierto) {
                $boveda->cicloAbierto->setAttribute('saldo_actual', $this->bovedaService->calcularSaldo($boveda->cicloAbierto));
            }

            $saldos = $this->cuentaBancariaService->saldoTotalBoveda($boveda);
            $boveda->setAttribute('saldo_cuentas_bancarias', $saldos['cuentas_bancarias']);
            $boveda->setAttribute('saldo_total', $saldos['total']);
        });
    }
}
