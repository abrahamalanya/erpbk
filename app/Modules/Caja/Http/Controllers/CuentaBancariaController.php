<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Modules\Caja\Http\Requests\StoreConciliacionBancariaRequest;
use App\Modules\Caja\Http\Requests\StoreCuentaBancariaMovimientoRequest;
use App\Modules\Caja\Http\Requests\StoreCuentaBancariaRequest;
use App\Modules\Caja\Http\Requests\UpdateCuentaBancariaRequest;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Caja\Services\CuentaBancariaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CuentaBancariaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CuentaBancariaService $cuentaBancariaService) {}

    public function index(Boveda $boveda): JsonResponse
    {
        Gate::authorize('view', $boveda);

        $cuentas = $boveda->cuentasBancarias()->with('banco')->orderBy('created_at')->get();
        $cuentas->each(fn (CuentaBancaria $cuenta) => $cuenta->setAttribute('saldo_actual', $cuenta->saldoActual()));

        return $this->successResponse($cuentas);
    }

    public function store(StoreCuentaBancariaRequest $request, Boveda $boveda): JsonResponse
    {
        Gate::authorize('crear', [CuentaBancaria::class, $boveda]);

        $cuenta = $this->cuentaBancariaService->crear($boveda, $request->user(), $request->validated());

        return $this->successResponse($cuenta->load('banco'), 'Cuenta bancaria creada', 201);
    }

    public function show(CuentaBancaria $cuentaBancaria): JsonResponse
    {
        Gate::authorize('view', $cuentaBancaria);

        $cuentaBancaria->load('banco', 'boveda');
        $cuentaBancaria->setAttribute('saldo_actual', $cuentaBancaria->saldoActual());

        return $this->successResponse($cuentaBancaria);
    }

    public function update(UpdateCuentaBancariaRequest $request, CuentaBancaria $cuentaBancaria): JsonResponse
    {
        Gate::authorize('editar', $cuentaBancaria);

        $cuenta = $this->cuentaBancariaService->actualizar($cuentaBancaria, $request->validated());

        return $this->successResponse($cuenta->load('banco'), 'Cuenta bancaria actualizada');
    }

    public function destroy(CuentaBancaria $cuentaBancaria): JsonResponse
    {
        Gate::authorize('eliminar', $cuentaBancaria);

        $this->cuentaBancariaService->eliminar($cuentaBancaria);

        return $this->successResponse(null, 'Cuenta bancaria eliminada');
    }

    public function movimiento(StoreCuentaBancariaMovimientoRequest $request, CuentaBancaria $cuentaBancaria): JsonResponse
    {
        Gate::authorize('movimiento', $cuentaBancaria);

        $movimiento = $this->cuentaBancariaService->registrarMovimiento(
            $cuentaBancaria,
            $request->user(),
            $request->validated('tipo'),
            (string) $request->validated('monto'),
            $request->validated('concepto'),
        );

        return $this->successResponse($movimiento, 'Movimiento registrado', 201);
    }

    /**
     * Powers the "reporte de ingresos y egresos" view: the paginated ledger
     * plus a resumen with totals across ALL movimientos (not just the
     * current page), so the report reads correctly no matter which page is
     * showing.
     */
    public function movimientos(CuentaBancaria $cuentaBancaria): JsonResponse
    {
        Gate::authorize('view', $cuentaBancaria);

        $movimientos = $cuentaBancaria->movimientos()->with('registradoPor')->latest('fecha')->latest('id')->paginate(15);

        return $this->successResponse([
            'movimientos' => $movimientos,
            'resumen' => [
                'total_ingresos' => bcadd((string) $cuentaBancaria->movimientos()->where('tipo', 'ingreso')->sum('monto'), '0', 2),
                'total_egresos' => bcadd((string) $cuentaBancaria->movimientos()->where('tipo', 'egreso')->sum('monto'), '0', 2),
            ],
        ]);
    }

    public function conciliar(StoreConciliacionBancariaRequest $request, CuentaBancaria $cuentaBancaria): JsonResponse
    {
        Gate::authorize('conciliar', $cuentaBancaria);

        $conciliacion = $this->cuentaBancariaService->conciliar(
            $cuentaBancaria,
            $request->user(),
            (string) $request->validated('saldo_banco'),
            $request->validated('observacion'),
        );

        return $this->successResponse($conciliacion, 'Conciliación registrada', 201);
    }

    public function conciliaciones(CuentaBancaria $cuentaBancaria): JsonResponse
    {
        Gate::authorize('view', $cuentaBancaria);

        $conciliaciones = $cuentaBancaria->conciliaciones()->with('conciliadoPor')->latest('fecha')->latest('id')->paginate(15);

        return $this->successResponse($conciliaciones);
    }
}
