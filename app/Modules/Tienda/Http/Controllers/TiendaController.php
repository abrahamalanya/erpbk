<?php

namespace App\Modules\Tienda\Http\Controllers;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Sistemas\Services\NotificacionService;
use App\Modules\Tienda\Http\Requests\StoreInteresRequest;
use App\Modules\Tienda\Notifications\InteresArticuloNotification;
use App\Modules\Tienda\Services\TiendaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Legacy bien-only storefront endpoints (`/tienda/bienes/*`). Kept for
 * backward compatibility; the unified, multi-tipo surface is
 * TiendaArticuloController (`/tienda/articulos/*`). Here `?tipo=` still
 * means the bien's own tipo column (electro / varios).
 */
class TiendaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly NotificacionService $notificaciones,
        private readonly TiendaService $tienda,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Bien::query()
            ->where('estado', 'disponible_venta')
            ->with(['fotos', 'agencia:id,empresa_id,nombre', 'empresa:id,nombre']);

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->string('tipo'));
        }

        if ($request->filled('empresa_id')) {
            $query->where('empresa_id', $request->integer('empresa_id'));
        }

        if ($request->filled('agencia_id')) {
            $query->where('agencia_id', $request->integer('agencia_id'));
        }

        $bienes = $query->latest()->paginate(12);
        $bienes->getCollection()->transform(fn (Bien $bien): array => $this->tienda->datosPublicos($bien));

        return $this->successResponse($bienes);
    }

    public function show(Bien $bien): JsonResponse
    {
        abort_unless($bien->estado === 'disponible_venta', 404);

        return $this->successResponse(
            $this->tienda->datosPublicos($bien->load(['fotos', 'agencia:id,empresa_id,nombre', 'empresa:id,nombre']))
        );
    }

    public function interes(StoreInteresRequest $request, Bien $bien): JsonResponse
    {
        abort_unless($bien->estado === 'disponible_venta', 404);

        $interes = $this->tienda->registrarInteres($bien, $request->validated());

        $this->notificaciones->enviar($this->tienda->controladoresDe($bien), new InteresArticuloNotification($interes));

        return $this->successResponse(null, 'Gracias, en breve te contactaremos.', 201);
    }
}
