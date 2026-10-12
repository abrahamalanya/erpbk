<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Planilla\Models\Planilla;
use App\Modules\Reportes\Services\ReportePlanillaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ExcelGeneratorService;
use App\Nucleo\Services\PdfGeneratorService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportePlanillaController extends Controller
{
    use ApiResponse;

    /**
     * @var list<string>
     */
    private const ENCABEZADOS = [
        'Doc. Tipo', 'Doc. Número', 'Apellidos', 'Nombres', 'Sit.',
        'Ingresos devengado', 'Ingresos pagado', 'Descuentos',
        'Tributos y aportes del trabajador', 'Neto a pagar', 'Tributos y aportes del empleador',
    ];

    public function __construct(private readonly ReportePlanillaService $reportePlanillaService) {}

    /**
     * Listado de planillas del actor (resumen, sin líneas). ?periodo= filtra
     * por 'YYYY-MM'.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Planilla::class);

        return $this->successResponse(
            $this->reportePlanillaService->planillas($request->user(), $request->query('periodo'))
                ->map(fn (Planilla $p): array => [
                    'id' => $p->id,
                    'periodo' => $p->periodo,
                    'fecha_pago' => $p->fecha_pago->toDateString(),
                    'agencia' => $p->agencia?->nombre,
                    'estado' => $p->estado,
                    'total_ingresos' => $p->total_ingresos,
                    'total_descuentos' => $p->total_descuentos,
                    'total_tributos_trabajador' => $p->total_tributos_trabajador,
                    'total_neto' => $p->total_neto,
                    'total_aportes_empleador' => $p->total_aportes_empleador,
                ])
        );
    }

    /**
     * El reporte de una planilla: una fila por trabajador con los datos
     * declarados y los importes, más los totales de la planilla.
     */
    public function planilla(Planilla $planilla): JsonResponse
    {
        Gate::authorize('view', $planilla);

        return $this->successResponse($this->reportePlanillaService->detalle($planilla));
    }

    public function planillaPdf(Planilla $planilla, PdfGeneratorService $pdf): Response
    {
        Gate::authorize('view', $planilla);

        $reporte = $this->reportePlanillaService->detalle($planilla);

        return $pdf->renderizarDesdeVista('reportes.planilla', [
            'titulo' => 'R01: Trabajadores - Datos de Ingresos, Tributos y Aportes',
            'ruc' => $reporte['ruc'],
            'empleador' => $reporte['empleador'],
            'periodo' => $reporte['periodo_plame'],
            'agencia' => $reporte['agencia'] ?? 'Todas las agencias',
            'fecha_pago' => $reporte['fecha_pago'],
            'filas' => $this->filas($reporte['trabajadores']),
            'totales' => $reporte['totales'],
        ]);
    }

    public function planillaExcel(Planilla $planilla, ExcelGeneratorService $excel): StreamedResponse
    {
        Gate::authorize('view', $planilla);

        $reporte = $this->reportePlanillaService->detalle($planilla);

        $filas = $this->filas($reporte['trabajadores']);
        $filas[] = [
            '', '', 'TOTALES', '', '',
            ...array_map(
                fn (string $monto): string => number_format((float) $monto, 2),
                array_values($reporte['totales']),
            ),
        ];

        return $excel->generarDesdeFilas(
            "Planilla {$reporte['periodo']}",
            self::ENCABEZADOS,
            $filas,
        );
    }

    /**
     * @param  list<array<string, string>>  $trabajadores
     * @return list<list<string>>
     */
    private function filas(array $trabajadores): array
    {
        return array_map(fn (array $t): array => [
            $t['tipo_documento'],
            $t['numero_documento'],
            $t['apellidos'],
            $t['nombres'],
            $t['situacion'],
            number_format((float) $t['ingresos_devengado'], 2),
            number_format((float) $t['ingresos_pagado'], 2),
            number_format((float) $t['descuentos'], 2),
            number_format((float) $t['tributos_trabajador'], 2),
            number_format((float) $t['neto'], 2),
            number_format((float) $t['aportes_empleador'], 2),
        ], $trabajadores);
    }
}
