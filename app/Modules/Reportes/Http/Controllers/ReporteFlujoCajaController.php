<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Caja\Models\Caja;
use App\Modules\Reportes\Services\ReporteFlujoCajaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ReporteFlujoCajaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteFlujoCajaService $reporteFlujoCajaService) {}

    /**
     * Flujo de caja: saldo en vivo + billetaje/ingresos/egresos/cobranza/
     * desembolsos del rango de fechas, desglosados por asesor — misma
     * autoridad y jerarquía de visibilidad que "Cajas: aperturas y cierres".
     */
    public function flujoCaja(): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        request()->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $hoy = now()->toDateString();

        return $this->successResponse($this->reporteFlujoCajaService->flujoCaja(
            request()->user(),
            (string) (request()->string('desde')->toString() ?: $hoy),
            (string) (request()->string('hasta')->toString() ?: $hoy),
            request()->integer('agencia_id') ?: null,
            request()->integer('asesor_id') ?: null,
        ));
    }

    /**
     * Resumen anual (enero a diciembre) de las mismas 5 categorías, para el
     * gráfico lineal.
     */
    public function flujoCajaAnual(): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        request()->validate(['anio' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        return $this->successResponse($this->reporteFlujoCajaService->flujoCajaAnual(
            request()->user(),
            (int) (request()->integer('anio') ?: now()->year),
            request()->integer('agencia_id') ?: null,
            request()->integer('asesor_id') ?: null,
        ));
    }

    /**
     * Resumen mensual (todos los días del mes indicado) de las mismas 5
     * categorías, para el gráfico lineal.
     */
    public function flujoCajaMensual(): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        request()->validate(['mes' => ['nullable', 'date_format:Y-m']]);

        return $this->successResponse($this->reporteFlujoCajaService->flujoCajaMensual(
            request()->user(),
            (string) (request()->string('mes')->toString() ?: now()->format('Y-m')),
            request()->integer('agencia_id') ?: null,
            request()->integer('asesor_id') ?: null,
        ));
    }
}
