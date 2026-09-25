<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Reportes\Services\ReporteCobranzaMensualService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ReporteCobranzaMensualController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteCobranzaMensualService $reporteCobranzaMensualService) {}

    /**
     * Cobranza mensual: total cobrado por día, en gráfico de barras
     * horizontal — solo administrador_general y sistemas, un alcance más
     * estrecho que el permiso 'cobranzas.ver' compartido (que también
     * tienen administrador_agencia/supervisor/asesor).
     */
    public function cobranzaMensual(): JsonResponse
    {
        $actor = request()->user();

        abort_unless($actor->hasAnyRole(['sistemas', 'administrador_general']), 403);

        request()->validate(['mes' => ['nullable', 'date_format:Y-m']]);

        return $this->successResponse($this->reporteCobranzaMensualService->cobranzaMensual(
            $actor,
            (string) (request()->string('mes')->toString() ?: now()->format('Y-m')),
            request()->integer('empresa_id') ?: null,
            request()->integer('agencia_id') ?: null,
            request()->integer('asesor_id') ?: null,
        ));
    }

    /**
     * Cobranza anual: total cobrado y desembolsado por mes (enero a
     * diciembre) del año indicado, en gráficos de barras verticales — resumen
     * de qué tan bien le fue a cada mes, mismos filtros y acceso que
     * cobranzaMensual().
     */
    public function cobranzaAnual(): JsonResponse
    {
        $actor = request()->user();

        abort_unless($actor->hasAnyRole(['sistemas', 'administrador_general']), 403);

        request()->validate(['anio' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        return $this->successResponse($this->reporteCobranzaMensualService->cobranzaAnual(
            $actor,
            (int) (request()->integer('anio') ?: now()->year),
            request()->integer('empresa_id') ?: null,
            request()->integer('agencia_id') ?: null,
            request()->integer('asesor_id') ?: null,
        ));
    }
}
