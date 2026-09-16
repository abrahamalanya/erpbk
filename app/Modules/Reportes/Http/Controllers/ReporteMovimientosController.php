<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Reportes\Services\ReporteMovimientosService;
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

class ReporteMovimientosController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteMovimientosService $reporteMovimientosService) {}

    /**
     * Reporte de movimientos de dinero (efectivo y cuentas bancarias) de las
     * bóvedas visibles para el actor. Misma autoridad que "ver bóvedas" —
     * quien puede ver las bóvedas puede ver cómo se movió su dinero.
     */
    public function movimientosDinero(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Boveda::class);

        return $this->successResponse($this->obtenerReporte($request));
    }

    public function movimientosDineroPdf(Request $request, PdfGeneratorService $pdf): Response
    {
        Gate::authorize('viewAny', Boveda::class);

        return $pdf->renderizarDesdeVista('reportes.tabla', [
            'titulo' => 'Movimientos de dinero',
            'encabezados' => self::ENCABEZADOS,
            'filas' => $this->obtenerReporte($request)->map(self::mapearFila(...))->all(),
        ]);
    }

    public function movimientosDineroExcel(Request $request, ExcelGeneratorService $excel): StreamedResponse
    {
        Gate::authorize('viewAny', Boveda::class);

        return $excel->generarDesdeFilas(
            'Movimientos de dinero',
            self::ENCABEZADOS,
            $this->obtenerReporte($request)->map(self::mapearFila(...))->all(),
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function obtenerReporte(Request $request): Collection
    {
        $bovedaId = $request->query('boveda_id');

        return $this->reporteMovimientosService->movimientosDinero(
            $request->user(),
            $request->query('desde'),
            $request->query('hasta'),
            $request->query('medio'),
            $bovedaId !== null ? (int) $bovedaId : null,
        );
    }

    /**
     * @var list<string>
     */
    private const ENCABEZADOS = ['Fecha', 'Bóveda', 'Medio', 'Tipo', 'Monto', 'Concepto', 'Registrado por'];

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private static function mapearFila(array $item): array
    {
        $registradoPor = $item['registrado_por'];

        return [
            $item['fecha'],
            $item['boveda'],
            $item['medio'] === 'efectivo' ? 'Efectivo' : ($item['cuenta_bancaria']?->banco?->nombre ?? 'Cuenta bancaria'),
            $item['tipo'] === 'ingreso' ? 'Ingreso' : 'Egreso',
            number_format((float) $item['monto'], 2),
            $item['concepto'] ?? '—',
            $registradoPor ? trim($registradoPor->nombre.' '.$registradoPor->apellido) : '—',
        ];
    }
}
