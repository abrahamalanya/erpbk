<?php

namespace App\Modules\Ubicacion\Http\Controllers;

use App\Modules\Ubicacion\Http\Requests\StoreUbicacionAsesorRequest;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Ubicacion\Services\UbicacionAsesorService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
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

    public function __construct(private readonly UbicacionAsesorService $ubicacionAsesorService) {}

    /**
     * Un asesor registra su propia ubicación — no requiere que otro rol
     * se lo autorice, igual que RutaCobranzaController::reordenar().
     */
    public function store(StoreUbicacionAsesorRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('asesor'), 403);

        $ubicacion = $this->ubicacionAsesorService->registrar($request->user(), $request->validated());

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
}
