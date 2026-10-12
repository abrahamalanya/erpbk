<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Caja\Models\Caja;
use App\Modules\Reportes\Services\ReporteIngresosEgresosService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ExcelGeneratorService;
use App\Nucleo\Services\PdfGeneratorService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteIngresosEgresosController extends Controller
{
    use ApiResponse;

    /**
     * Las 4 secciones del reporte, en el orden en que se muestran: primero
     * todo lo que entra, luego todo lo que sale.
     *
     * @var list<array{clave: string, titulo: string}>
     */
    private const SECCIONES = [
        ['clave' => 'ingresos', 'titulo' => 'Ingresos'],
        ['clave' => 'cobranzas', 'titulo' => 'Cobranzas'],
        ['clave' => 'ventas', 'titulo' => 'Ventas'],
        ['clave' => 'egresos', 'titulo' => 'Egresos'],
    ];

    public function __construct(private readonly ReporteIngresosEgresosService $reporteIngresosEgresosService) {}

    /**
     * Estado de resultados: ingresos, cobranzas (interés + mora de créditos
     * cancelados), ventas (ganancia) y egresos del rango de fechas, con su
     * detalle y total por sección, más utilidad/pérdida neta y margen de
     * utilidad bruta. Misma autoridad y jerarquía de visibilidad que "Flujo de
     * caja".
     *
     * Sin fechas manda el mes en curso, no un solo día: un estado de resultados
     * de un día no dice nada. El frontend puede pasar el rango que quiera.
     */
    public function ingresosEgresos(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Caja::class);

        return $this->successResponse($this->obtenerReporte($request));
    }

    public function ingresosEgresosPdf(Request $request, PdfGeneratorService $pdf): Response
    {
        Gate::authorize('viewAny', Caja::class);

        $reporte = $this->obtenerReporte($request);

        return $pdf->renderizarDesdeVista('reportes.ingresos-egresos', [
            'titulo' => 'Ingresos y Egresos',
            'secciones' => $this->seccionesPara($reporte),
            'totales' => $reporte['totales'],
            'utilidad_neta' => number_format((float) $reporte['utilidad_neta'], 2),
            'margen_utilidad_bruta' => $this->margenLegible($reporte['margen_utilidad_bruta']),
        ]);
    }

    public function ingresosEgresosExcel(Request $request, ExcelGeneratorService $excel): StreamedResponse
    {
        Gate::authorize('viewAny', Caja::class);

        $reporte = $this->obtenerReporte($request);

        return $excel->generarDesdeFilas(
            'Ingresos y Egresos',
            ['Sección', 'Cuenta', 'Fecha', 'Descripción', 'Monto'],
            $this->filasPara($reporte),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function obtenerReporte(Request $request): array
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $mesEnCurso = now()->startOfMonth();

        return $this->reporteIngresosEgresosService->ingresosEgresos(
            $request->user(),
            (string) ($request->string('desde')->toString() ?: $mesEnCurso->toDateString()),
            (string) ($request->string('hasta')->toString() ?: now()->toDateString()),
            $request->integer('agencia_id') ?: null,
        );
    }

    /**
     * Las 4 secciones con su título y sus filas ya formateadas para la vista
     * PDF.
     *
     * @param  array<string, mixed>  $reporte
     * @return list<array{titulo: string, filas: list<array<string, string>>, total: string}>
     */
    private function seccionesPara(array $reporte): array
    {
        return array_map(fn (array $seccion): array => [
            'titulo' => $seccion['titulo'],
            'filas' => collect($reporte[$seccion['clave']]['filas'])
                ->map(self::formatearFila(...))
                ->all(),
            'total' => number_format((float) $reporte[$seccion['clave']]['total'], 2),
        ], self::SECCIONES);
    }

    /**
     * El mismo reporte aplanado a una sola tabla (cada sección con sus filas y
     * su total) y luego el cuadro de utilidad/margen — que es lo que el
     * generador de .xlsx sabe pintar, ya que solo entiende una grilla.
     *
     * @param  array<string, mixed>  $reporte
     * @return list<list<string>>
     */
    private function filasPara(array $reporte): array
    {
        $filas = [];

        foreach ($this->seccionesPara($reporte) as $seccion) {
            foreach ($seccion['filas'] as $fila) {
                $filas[] = [$seccion['titulo'], $fila['cuenta'], $fila['fecha'], $fila['descripcion'], $fila['monto']];
            }

            $filas[] = ['', 'Total '.$seccion['titulo'], '', '', $seccion['total']];
        }

        $filas[] = ['', 'Total ingresos', '', '', number_format((float) $reporte['totales']['total_ingresos'], 2)];
        $filas[] = ['', 'Total egresos', '', '', number_format((float) $reporte['totales']['total_egresos'], 2)];
        $filas[] = ['', 'Utilidad / Pérdida neta', '', '', number_format((float) $reporte['utilidad_neta'], 2)];
        $filas[] = ['', 'Margen de utilidad bruta', '', '', $this->margenLegible($reporte['margen_utilidad_bruta'])];

        return $filas;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, string>
     */
    private static function formatearFila(array $fila): array
    {
        return [
            'cuenta' => (string) $fila['cuenta'],
            'fecha' => (string) $fila['fecha'],
            'descripcion' => (string) $fila['descripcion'],
            'monto' => number_format((float) $fila['monto'], 2),
        ];
    }

    /**
     * El margen viene como ratio (utilidad/ingresos, p. ej. 0.25); en el
     * documento va como porcentaje, que es como se lee un margen.
     */
    private function margenLegible(?float $margen): string
    {
        return $margen === null ? '—' : number_format($margen * 100, 2).'%';
    }
}
