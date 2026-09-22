<?php

namespace App\Modules\Tienda\Http\Controllers;

use App\Modules\Tienda\Models\InteresArticulo;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Lado admin de las solicitudes de la tienda virtual ("me interesa"),
 * registradas públicamente vía TiendaArticuloController::interes(). Ver
 * App\Modules\Tienda\Models\InteresArticulo.
 */
class InteresArticuloAdminController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', InteresArticulo::class);

        $actor = $request->user();
        $query = InteresArticulo::query()->with('articulo')->latest();

        if (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        if ($actor->hasAnyRole(['administrador_agencia', 'asesor'])) {
            $query->where('agencia_id', $actor->agencia_id);
        }

        if ($request->boolean('pendientes')) {
            $query->whereNull('atendido_at');
        }

        return $this->successResponse($query->paginate(20));
    }

    public function atender(InteresArticulo $interes): JsonResponse
    {
        Gate::authorize('atender', $interes);

        $interes->update(['atendido_at' => now()]);

        return $this->successResponse($interes->fresh(), 'Solicitud marcada como atendida');
    }

    public function destroy(InteresArticulo $interes): JsonResponse
    {
        Gate::authorize('delete', $interes);

        $interes->delete();

        return $this->successResponse(null, 'Solicitud eliminada');
    }
}
