<?php

namespace App\Modules\Reportes\Http\Controllers;

use App\Modules\Reportes\Http\Requests\ReporteCumpleanosRequest;
use App\Modules\Reportes\Services\ReporteCumpleanosService;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ExcelGeneratorService;
use App\Nucleo\Services\PdfGeneratorService;
use App\Nucleo\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte de cumpleaños de los usuarios del sistema, por el día/mes de su
 * fecha de nacimiento. Misma autoridad que "ver usuarios" (usuarios.ver).
 */
class ReporteCumpleanosController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReporteCumpleanosService $reporteCumpleanosService) {}

    public function cumpleanos(ReporteCumpleanosRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        return $this->successResponse($this->obtenerReporte($request));
    }

    public function cumpleanosPdf(ReporteCumpleanosRequest $request, PdfGeneratorService $pdf): Response
    {
        Gate::authorize('viewAny', User::class);

        return $pdf->renderizarDesdeVista('reportes.tabla', [
            'titulo' => 'Reporte de cumpleaños',
            'encabezados' => self::ENCABEZADOS,
            'filas' => $this->obtenerReporte($request)->map(self::mapearFila(...))->all(),
        ]);
    }

    public function cumpleanosExcel(ReporteCumpleanosRequest $request, ExcelGeneratorService $excel): StreamedResponse
    {
        Gate::authorize('viewAny', User::class);

        return $excel->generarDesdeFilas(
            'Reporte de cumpleaños',
            self::ENCABEZADOS,
            $this->obtenerReporte($request)->map(self::mapearFila(...))->all(),
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function obtenerReporte(ReporteCumpleanosRequest $request): Collection
    {
        return $this->reporteCumpleanosService->cumpleanos(
            $request->user(),
            $request->desde(),
            $request->hasta(),
            $request->agenciaId(),
            $request->usuarioId(),
        );
    }

    /**
     * @var list<string>
     */
    private const ENCABEZADOS = [
        'Usuario', 'Documento', 'Teléfono', 'Fecha de nacimiento', 'Cumple años',
        'Próximo cumpleaños', 'Agencia', 'Cargo',
    ];

    /**
     * @param  array<string, mixed>  $item
     * @return list<string|int>
     */
    private static function mapearFila(array $item): array
    {
        return [
            $item['nombre'],
            $item['dni'] ?? '—',
            $item['telefono'] ?? '—',
            $item['fecha_nacimiento'],
            $item['cumple_anios'],
            self::formatearProximo((int) $item['dias_para_cumple'], (string) $item['proximo_cumpleanos']),
            $item['agencia'] ?? 'Principal',
            $item['roles'] === [] ? '—' : implode(', ', array_map(ucfirst(...), $item['roles'])),
        ];
    }

    /**
     * "Hoy"/"Mañana" se leen mejor que una fecha cruda; para el resto se
     * muestra la fecha, que es lo que necesita la persona que va a felicitar.
     */
    private static function formatearProximo(int $dias, string $fecha): string
    {
        return match ($dias) {
            0 => 'Hoy',
            1 => 'Mañana',
            default => Carbon::parse($fecha)->format('d/m/Y'),
        };
    }
}
