<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Caja\Models\BovedaCiclo;
use App\Modules\Reportes\Services\ReporteCierreBovedaService;
use App\Modules\Reportes\Services\ReporteHistorialBovedaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\PdfGeneratorService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReporteHistorialBovedaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteHistorialBovedaService $historialService) {}

    /**
     * Historial de Bóveda: todas las bóvedas y todas las cajas del sistema de
     * un solo día, exclusivo para administrador_general/sistemas (ver
     * historial_boveda.ver en PermissionSeeder) — ni siquiera
     * administrador_agencia, que ya puede ver cajas/bóvedas de su propia
     * agencia, entra aquí.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('historial_boveda.ver'), 403);

        $validated = $request->validate([
            'fecha' => ['required', 'date'],
            'estado' => ['nullable', 'in:abierta,cerrada'],
            'agencia_id' => ['nullable', 'integer'],
        ]);

        return $this->successResponse($this->historialService->listar(
            $validated['fecha'],
            $validated['estado'] ?? null,
            isset($validated['agencia_id']) ? (int) $validated['agencia_id'] : null,
        ));
    }

    public function reporteBoveda(BovedaCiclo $ciclo, ReporteCierreBovedaService $reporte): JsonResponse
    {
        abort_unless(request()->user()->can('historial_boveda.ver'), 403);

        return $this->successResponse($reporte->reporte($ciclo));
    }

    public function reporteBovedaPdf(BovedaCiclo $ciclo, ReporteCierreBovedaService $reporte, PdfGeneratorService $pdf): Response
    {
        abort_unless(request()->user()->can('historial_boveda.ver'), 403);

        return $pdf->renderizarDesdeVista('reportes.cierre-boveda', $reporte->reporte($ciclo));
    }
}
