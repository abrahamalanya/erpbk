<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Credito\Models\Credito;
use App\Modules\Reportes\Services\ReporteCobranzaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ExcelGeneratorService;
use App\Nucleo\Services\PdfGeneratorService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteCobranzaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteCobranzaService $reporteCobranzaService) {}

    /**
     * Cobranza diaria: un cliente aparece una vez por cada crédito suyo con
     * una cuota vencida u hoy — misma autoridad que "ver créditos".
     */
    public function cobranzaDiaria(): JsonResponse
    {
        Gate::authorize('viewAny', Credito::class);

        return $this->successResponse($this->reporteCobranzaService->cobranzaDiaria(request()->user()));
    }

    public function cobranzaDiariaPdf(PdfGeneratorService $pdf): Response
    {
        Gate::authorize('viewAny', Credito::class);

        return $pdf->renderizarDesdeVista('reportes.tabla', [
            'titulo' => 'Cobranza diaria',
            'encabezados' => self::ENCABEZADOS,
            'filas' => $this->reporteCobranzaService->cobranzaDiaria(request()->user())->map(self::mapearFila(...))->all(),
        ]);
    }

    public function cobranzaDiariaExcel(ExcelGeneratorService $excel): StreamedResponse
    {
        Gate::authorize('viewAny', Credito::class);

        return $excel->generarDesdeFilas(
            'Cobranza diaria',
            self::ENCABEZADOS,
            $this->reporteCobranzaService->cobranzaDiaria(request()->user())->map(self::mapearFila(...))->all(),
        );
    }

    /**
     * @var list<string>
     */
    private const ENCABEZADOS = [
        'Código', 'Tipo', 'Cliente', 'Documento', 'Teléfono', 'Agencia', 'Estado',
        'Cuota más atrasada', 'Cuotas vencidas', 'Monto a cobrar sugerido',
    ];

    /**
     * @param  array<string, mixed>  $item
     * @return list<string|int>
     */
    private static function mapearFila(array $item): array
    {
        $cliente = $item['cliente'];
        $agencia = $item['agencia'];
        $montoSugerido = $item['monto_pago_cuota_sugerido']['total']
            ?? $item['monto_refrendo_sugerido']['total']
            ?? $item['monto_liquidacion_sugerido']['total']
            ?? '0';

        return [
            $item['credito_codigo'],
            ucfirst($item['tipo_credito']),
            strtoupper($cliente->nombre.' '.$cliente->apellido),
            $cliente->numero_documento ?? '—',
            $cliente->telefono ?? '—',
            strtoupper($agencia->nombre),
            ucfirst(str_replace('_', ' ', $item['estado'])),
            $item['vence_hoy'] ? 'Vence hoy' : "{$item['dias_atraso']} ".($item['dias_atraso'] === 1 ? 'día' : 'días').' de atraso',
            $item['cuotas_vencidas'],
            number_format((float) $montoSugerido, 2),
        ];
    }
}
