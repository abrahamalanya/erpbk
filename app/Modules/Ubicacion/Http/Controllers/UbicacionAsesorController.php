<?php

namespace App\Modules\Ubicacion\Http\Controllers;

use App\Modules\Ruta\Services\VisitaClienteService;
use App\Modules\Ubicacion\Http\Requests\HistorialUbicacionAsesorRequest;
use App\Modules\Ubicacion\Http\Requests\StoreUbicacionAsesorRequest;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Ubicacion\Services\UbicacionAsesorService;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Tracking en vivo de asesores: cada uno envía su propia ubicación desde el
 * tracking en segundo plano de la app; sistemas/admins/supervisores ven el
 * mapa con la última posición de los asesores dentro de su alcance.
 */
class UbicacionAsesorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UbicacionAsesorService $ubicacionAsesorService,
        private readonly VisitaClienteService $visitaClienteService,
    ) {}

    /**
     * Un asesor registra su propia ubicación — no requiere que otro rol
     * se lo autorice, igual que RutaCobranzaController::reordenar(). Cada
     * ping válido también evalúa la permanencia cerca de los clientes de su
     * ruta de hoy (ver VisitaClienteService), que es lo que alimenta el
     * estado "amarillo" del mapa unificado.
     */
    public function store(StoreUbicacionAsesorRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('asesor'), 403);

        $ubicacion = $this->ubicacionAsesorService->registrar($request->user(), $request->validated());
        $this->visitaClienteService->procesarPing($request->user(), $ubicacion);

        return $this->successResponse([
            'id' => $ubicacion->id,
            'capturado_en' => $ubicacion->capturado_en?->toISOString(),
        ], 'Ubicación registrada');
    }

    /**
     * Últimas ubicaciones visibles para el actor, para pintar el mapa.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', UbicacionAsesor::class);

        return $this->successResponse($this->ubicacionAsesorService->visiblesPara(request()->user()));
    }

    /**
     * Asesores cuyo historial puede consultar el actor, para el selector
     * del reporte — mismo alcance por rol que index().
     */
    public function asesoresVisibles(): JsonResponse
    {
        Gate::authorize('viewAny', UbicacionAsesor::class);

        $asesores = $this->ubicacionAsesorService->asesoresVisiblesPara(request()->user())
            ->map(fn (User $asesor): array => [
                'id' => $asesor->id,
                'nombre' => $asesor->nombre,
                'apellido' => $asesor->apellido,
            ]);

        return $this->successResponse($asesores);
    }

    /**
     * Historial de recorrido de un asesor en un rango de fechas, para el
     * reporte web — mismo alcance por rol que index(); además exige que el
     * asesor pedido esté dentro de ese alcance.
     */
    public function historial(HistorialUbicacionAsesorRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', UbicacionAsesor::class);

        $visibles = $this->ubicacionAsesorService->asesoresVisiblesPara($request->user());
        $asesor = $visibles->firstWhere('id', $request->asesorId());

        abort_unless($asesor, 403);

        return $this->successResponse($this->ubicacionAsesorService->historialDe(
            $asesor,
            CarbonImmutable::parse($request->desde()),
            CarbonImmutable::parse($request->hasta()),
        ));
    }
}
