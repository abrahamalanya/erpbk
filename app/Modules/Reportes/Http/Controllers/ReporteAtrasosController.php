<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Credito\Models\Credito;
use App\Modules\Reportes\Services\ReporteAtrasosService;
use App\Modules\Reportes\Support\Bolita;
use App\Modules\Reportes\Tipos\AtrasoCuota;
use App\Modules\Reportes\Tipos\AtrasoVencimiento;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ExcelGeneratorService;
use App\Nucleo\Services\PdfGeneratorService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteAtrasosController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteAtrasosService $reporteAtrasosService) {}

    /**
     * Atrasos diarios: una fila por crédito activo/vencido con los dos
     * medidores de mora (vencimiento del crédito completo y cuota vencida
     * impaga más antigua) ya categorizados y ordenados por riesgo — misma
     * autoridad que "ver créditos".
     */
    public function atrasos(): JsonResponse
    {
        Gate::authorize('viewAny', Credito::class);

        return $this->successResponse($this->reporteAtrasosService->atrasos(request()->user()));
    }

    public function atrasosPdf(PdfGeneratorService $pdf): Response
    {
        Gate::authorize('viewAny', Credito::class);

        $tabla = $this->armarTabla($this->reporteAtrasosService->atrasos(request()->user()));

        return $pdf->renderizarDesdeVista('reportes.atrasos', [
            'titulo' => 'Atrasos diarios',
            'encabezados' => $tabla['encabezados'],
            'filas' => $tabla['filas'],
            'bolitas' => $tabla['bolitas'],
            'leyenda' => $this->leyenda(),
        ]);
    }

    public function atrasosExcel(ExcelGeneratorService $excel): StreamedResponse
    {
        Gate::authorize('viewAny', Credito::class);

        $tabla = $this->armarTabla($this->reporteAtrasosService->atrasos(request()->user()));

        return $excel->generarDesdeFilas(
            'Atrasos diarios',
            $tabla['encabezados'],
            $tabla['filas'],
            $this->bolitasPorLetra($tabla['bolitas']),
        );
    }

    /**
     * Arma la grilla una sola vez para los dos exports: el texto de las
     * celdas y, aparte, el mapa de bolitas (columna => color) para que el
     * PDF dibuje el círculo y el Excel pinte el relleno.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array{encabezados: list<string>, filas: list<list<string>>, bolitas: array<int, array<int, Bolita>>}
     */
    private function armarTabla(Collection $filas): array
    {
        $valores = [];
        $bolitas = [];

        foreach ($filas as $indice => $fila) {
            $valores[] = [
                (string) ($fila['credito_codigo'] ?? '—'),
                strtoupper(trim(($fila['cliente']->nombre ?? '').' '.($fila['cliente']->apellido ?? ''))) ?: '—',
                strtoupper(trim(($fila['asesor']->nombre ?? '').' '.($fila['asesor']->apellido ?? ''))) ?: '—',
                $fila['fecha_desembolso'] ?? '—',
                $fila['fecha_vencimiento'] ?? '—',
                $fila['atraso_venc_dias'] > 0
                    ? $fila['atraso_venc_dias'].' '.($fila['atraso_venc_dias'] === 1 ? 'Día' : 'Días')
                    : 'Al día',
                $fila['cuotas_atrasadas'] > 0
                    ? $fila['cuotas_atrasadas'].' '.($fila['cuotas_atrasadas'] === 1 ? 'Cuota' : 'Cuotas')
                    : 'Al día',
                $fila['ultimo_abono'] ?? '—',
                self::PERIODICIDADES[$fila['tipo_cuota']] ?? ucfirst((string) ($fila['tipo_cuota'] ?? '—')),
                number_format($fila['monto_prestamo'], 2),
                number_format($fila['interes_prestamo'], 2),
                number_format($fila['total'], 2),
                number_format($fila['saldo'], 2),
            ];

            $bolitasDeLaFila = [
                self::COLUMNA_ATRASO_VENCIMIENTO => Bolita::desde($fila['atraso_venc_color']),
                self::COLUMNA_ATRASO_CUOTA => Bolita::desde($fila['atraso_cuota_color']),
            ];

            // Sin color no hay bolita: la celda queda solo con su texto, que
            // para un crédito al día dice "Al día".
            $bolitas[$indice] = array_filter($bolitasDeLaFila);
        }

        return [
            'encabezados' => self::ENCABEZADOS,
            'filas' => $valores,
            'bolitas' => $bolitas,
        ];
    }

    /**
     * Traduce el mapa de bolitas de índice numérico a letra de columna, que
     * es lo que el generador de Excel necesita para pintar el relleno.
     *
     * @param  array<int, array<int, Bolita>>  $bolitas
     * @return array<int, array<string, string>>
     */
    private function bolitasPorLetra(array $bolitas): array
    {
        $porLetra = [];

        foreach ($bolitas as $indiceFila => $columnas) {
            foreach ($columnas as $indiceColumna => $bolita) {
                $porLetra[$indiceFila][chr(ord('A') + $indiceColumna)] = $bolita->hex();
            }
        }

        return $porLetra;
    }

    /**
     * Leyenda de los dos semáforos, para que el PDF se lea solo: sin ella los
     * hex son indescifrables fuera del sistema.
     *
     * @return list<array{titulo: string, tramos: list<array{rango: string, texto: string, bolita: Bolita}>}>
     */
    private function leyenda(): array
    {
        return [
            [
                'titulo' => 'Atraso del crédito completo',
                'tramos' => collect(AtrasoVencimiento::cases())
                    ->sortBy(fn (AtrasoVencimiento $caso): int => $caso->rank())
                    ->map(fn (AtrasoVencimiento $caso): array => [
                        'rango' => $caso->diasMaximos() === null ? '+60' : '≤'.$caso->diasMaximos(),
                        'texto' => $caso->etiqueta(),
                        'bolita' => Bolita::desde($caso->color()),
                    ])
                    ->all(),
            ],
            [
                'titulo' => 'Atraso de cuotas',
                'tramos' => collect(AtrasoCuota::cases())
                    ->sortBy(fn (AtrasoCuota $caso): int => $caso->diasMaximos() ?? PHP_INT_MAX)
                    ->map(fn (AtrasoCuota $caso): array => [
                        'rango' => $caso->diasMaximos() === null ? '+20' : '≤'.$caso->diasMaximos(),
                        'texto' => $caso->diasMaximos() === null ? '+20 días' : '≤'.$caso->diasMaximos().' días',
                        'bolita' => Bolita::desde($caso->color()),
                    ])
                    ->all(),
            ],
        ];
    }

    /**
     * @var list<string>
     */
    private const ENCABEZADOS = [
        'Cod.Credito', 'Cliente', 'Asesor', 'FechaDesembolso', 'FechaVencimiento',
        'AtrasoVencimiento', 'AtrasoCuota', 'UltimoAbono', 'TipoPago',
        'MontoPrestamo', 'InteresPrestamo', 'Total', 'Saldo',
    ];

    private const COLUMNA_ATRASO_VENCIMIENTO = 5;

    private const COLUMNA_ATRASO_CUOTA = 6;

    /**
     * La columna guarda la periodicidad en masculino ('diario'), pero en la
     * tabla la periodicidad se lee en femenino ('Diaria').
     *
     * @var array<string, string>
     */
    private const PERIODICIDADES = [
        'diario' => 'Diaria',
        'semanal' => 'Semanal',
        'quincenal' => 'Quincenal',
        'mensual' => 'Mensual',
    ];
}
