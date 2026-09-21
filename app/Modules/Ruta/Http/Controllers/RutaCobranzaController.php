<?php

namespace App\Modules\Ruta\Http\Controllers;

use App\Modules\Credito\Models\Credito;
use App\Modules\Ruta\Http\Requests\MostrarRutaCobranzaRequest;
use App\Modules\Ruta\Http\Requests\ReordenarRutaCobranzaRequest;
use App\Modules\Ruta\Services\RutaCobranzaService;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Ruta de cobranza diaria: los clientes en mora de un asesor, en el orden de
 * visita que el propio asesor definió (persistente entre días). Alimenta
 * tanto la lista reordenable (drag & drop) como el mapa con paradas
 * numeradas del panel principal.
 */
class RutaCobranzaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RutaCobranzaService $rutaCobranzaService) {}

    /**
     * Ruta de $asesorId (o la propia del actor si se omite). Un admin/
     * supervisor puede pedir la de cualquier asesor dentro de su alcance.
     */
    public function show(MostrarRutaCobranzaRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Credito::class);

        $asesor = $this->resolverAsesor();

        return $this->successResponse($this->rutaCobranzaService->rutaDe($asesor, $request->validated('tipo_credito')));
    }

    /**
     * Asesores visibles para el actor, para el selector del mapa de ruta.
     */
    public function asesores(): JsonResponse
    {
        Gate::authorize('viewAny', Credito::class);

        return $this->successResponse($this->rutaCobranzaService->asesoresVisibles(request()->user()));
    }

    /**
     * Reordena la ruta del actor mismo (solo un asesor puede reordenar su
     * propia cartera, no la de otro).
     */
    public function reordenar(ReordenarRutaCobranzaRequest $request): JsonResponse
    {
        $tipoCredito = $request->validated('tipo_credito');

        $this->rutaCobranzaService->reordenar($request->user(), $request->validated()['cliente_ids'], $tipoCredito);

        return $this->successResponse($this->rutaCobranzaService->rutaDe($request->user(), $tipoCredito), 'Ruta actualizada');
    }

    private function resolverAsesor(): User
    {
        $actor = request()->user();
        $asesorId = request()->integer('asesor_id') ?: null;

        if ($asesorId === null || $asesorId === $actor->id) {
            return $actor;
        }

        $asesor = User::query()->findOrFail($asesorId);

        abort_unless($this->rutaCobranzaService->puedeVerRutaDe($actor, $asesor), 403);

        return $asesor;
    }
}
