<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Modules\Caja\Http\Requests\StoreBovedaAperturaRequest;
use App\Modules\Caja\Http\Requests\StoreBovedaInyeccionRequest;
use App\Modules\Caja\Http\Requests\StoreCajaCierreRequest;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Services\BovedaService;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
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

        $movimiento = $this->bovedaService->inyectar(
            $boveda,
            $request->user(),
            (string) $request->validated('monto'),
            $request->validated('concepto'),
        );

        return $this->successResponse($movimiento, 'Capital inyectado', 201);
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
        });
    }
}
