<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Modules\Caja\Http\Requests\StoreCajaCierreRequest;
use App\Modules\Caja\Http\Requests\StoreCajaMovimientoRequest;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Caja\Services\CajaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CajaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CajaService $cajaService,
        private readonly CajaBovedaHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        $query = Caja::query()->with(['user.roles', 'agencia', 'cicloAbierto']);
        $query = $this->hierarchy->cajasVisibles($query, request()->user());

        return $this->successResponse($query->paginate(15));
    }

    public function miCaja(): JsonResponse
    {
        $caja = $this->cajaService->cajaDe(request()->user())->load(['cicloAbierto']);

        return $this->successResponse([
            ...$caja->toArray(),
            'saldo_actual' => $caja->cicloAbierto?->saldoActual(),
        ]);
    }

    public function show(Caja $caja): JsonResponse
    {
        Gate::authorize('view', $caja);

        return $this->successResponse($caja->load(['user', 'agencia', 'cicloAbierto']));
    }

    public function aperturar(): JsonResponse
    {
        Gate::authorize('aperturar', Caja::class);

        $ciclo = $this->cajaService->aperturar(request()->user());

        return $this->successResponse($ciclo, 'Caja aperturada', 201);
    }

    public function cerrar(StoreCajaCierreRequest $request): JsonResponse
    {
        Gate::authorize('cerrar', Caja::class);

        $ciclo = $this->cajaService->cerrar($request->user(), (string) $request->validated('monto_contado'));

        return $this->successResponse($ciclo, 'Caja cerrada');
    }

    /**
     * Detalle de movimientos del ciclo abierto, para revisar antes de
     * confirmar el cierre (el frontend calcula sobrante/faltante en vivo
     * restando esto del monto_contado que el usuario está tipeando).
     */
    public function resumenCierre(): JsonResponse
    {
        Gate::authorize('cerrar', Caja::class);

        $ciclo = $this->cajaService->resumenCierre(request()->user());

        return $this->successResponse($ciclo);
    }

    public function registrarMovimiento(StoreCajaMovimientoRequest $request): JsonResponse
    {
        Gate::authorize('registrarMovimiento', Caja::class);

        $movimiento = $this->cajaService->registrarMovimiento(
            $request->user(),
            $request->validated('tipo'),
            (int) $request->validated('concepto_id'),
            (string) $request->validated('monto'),
            $request->file('comprobante'),
            $request->file('fotos_adicionales', []),
        );

        return $this->successResponse($movimiento, 'Movimiento registrado', 201);
    }

    /**
     * The actor's own ingreso/gasto history — powers the Ingresos/Gastos
     * modules. ?tipo= is required (ingreso|egreso) since these are two
     * separate frontend pages, never a combined feed.
     */
    public function movimientos(): JsonResponse
    {
        Gate::authorize('registrarMovimiento', Caja::class);

        request()->validate(['tipo' => ['required', 'string', 'in:ingreso,egreso']]);

        $movimientos = $this->cajaService->listarMovimientos(request()->user(), request()->string('tipo')->value());

        return $this->successResponse($movimientos);
    }

    public function cerrarForzado(StoreCajaCierreRequest $request, Caja $caja): JsonResponse
    {
        Gate::authorize('cerrarForzado', $caja);

        $ciclo = $this->cajaService->cerrarForzado($request->user(), $caja, (string) $request->validated('monto_contado'));

        return $this->successResponse($ciclo, 'Caja cerrada (forzado)');
    }

    public function reabrir(Caja $caja): JsonResponse
    {
        Gate::authorize('reabrir', $caja);

        $ciclo = $this->cajaService->reabrir($caja, request()->user());

        return $this->successResponse($ciclo, 'Caja reabierta');
    }
}
