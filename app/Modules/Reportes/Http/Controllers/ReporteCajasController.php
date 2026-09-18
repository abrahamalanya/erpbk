<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Reportes\Services\ReporteCajasService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ExcelGeneratorService;
use App\Nucleo\Services\PdfGeneratorService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteCajasController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteCajasService $reporteCajasService) {}

    /**
     * Reporte de aperturas y cierres de caja (uno por CajaCiclo) de las cajas
     * visibles para el actor. Misma autoridad que "ver cajas".
     */
    public function aperturasCierres(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        return $this->successResponse($this->obtenerReporte($request));
    }

    public function aperturasCierresPdf(Request $request, PdfGeneratorService $pdf): Response
    {
        Gate::authorize('viewAny', Caja::class);

        return $pdf->renderizarDesdeVista('reportes.tabla', [
            'titulo' => 'Cajas: aperturas y cierres',
            'encabezados' => self::ENCABEZADOS,
            'filas' => $this->obtenerReporte($request)->map(self::mapearFila(...))->all(),
        ]);
    }

    public function aperturasCierresExcel(Request $request, ExcelGeneratorService $excel): StreamedResponse
    {
        Gate::authorize('viewAny', Caja::class);

        return $excel->generarDesdeFilas(
            'Cajas: aperturas y cierres',
            self::ENCABEZADOS,
            $this->obtenerReporte($request)->map(self::mapearFila(...))->all(),
        );
    }

    /**
     * Desglose línea por línea (billetajes, ingresos, egresos, cobranzas por
     * medio y desembolsos) de un ciclo puntual, más el cálculo de saldo —
     * cargado bajo demanda al abrir "Ver detalle" en el reporte.
     */
    public function detalle(CajaCiclo $ciclo): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        return $this->successResponse($this->reporteCajasService->detalle(request()->user(), $ciclo));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function obtenerReporte(Request $request): Collection
    {
        $agenciaId = $request->query('agencia_id');

        return $this->reporteCajasService->aperturasCierres(
            $request->user(),
            $request->query('desde'),
            $request->query('hasta'),
            $agenciaId !== null ? (int) $agenciaId : null,
            $request->query('estado'),
        );
    }

    /**
     * @var list<string>
     */
    private const ENCABEZADOS = [
        'Fecha apertura', 'Fecha cierre', 'Usuario', 'Agencia', 'Estado', 'Valor aperturado', 'Valor cerrado',
        'Total ingresos', 'Total egresos', 'Total billetaje', 'Total cobranza', 'Total desembolso',
    ];

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private static function mapearFila(array $item): array
    {
        $usuario = $item['usuario'];

        return [
            $item['fecha_apertura']?->format('d/m/Y H:i') ?? '—',
            $item['fecha_cierre']?->format('d/m/Y H:i') ?? '—',
            $usuario ? trim($usuario->nombre.' '.$usuario->apellido) : '—',
            $item['agencia'],
            $item['estado'] === 'abierta' ? 'Abierta' : 'Cerrada',
            number_format((float) $item['valor_aperturado'], 2),
            $item['valor_cerrado'] !== null ? number_format((float) $item['valor_cerrado'], 2) : '—',
            number_format((float) $item['total_ingresos'], 2),
            number_format((float) $item['total_egresos'], 2),
            number_format((float) $item['total_billetaje'], 2),
            number_format((float) $item['total_cobranza'], 2),
            number_format((float) $item['total_desembolso'], 2),
        ];
    }
}
