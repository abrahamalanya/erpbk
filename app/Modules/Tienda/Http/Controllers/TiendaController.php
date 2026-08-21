<?php

namespace App\Modules\Tienda\Http\Controllers;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Sistemas\Services\NotificacionService;
use App\Modules\Tienda\Http\Requests\StoreInteresBienRequest;
use App\Modules\Tienda\Models\InteresBien;
use App\Modules\Tienda\Notifications\InteresBienNotification;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Public storefront for bienes en estado disponible_venta (créditos
 * prendarios rematados) — no auth:sanctum, reachable by anyone. Only
 * publishes fields safe to show a stranger (no cliente, no registrado_por,
 * no observación interna): see toPublicArray().
 */
class TiendaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly NotificacionService $notificaciones,
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
        $bienes->getCollection()->transform(fn (Bien $bien): array => $this->toPublicArray($bien));

        return $this->successResponse($bienes);
    }

    public function show(Bien $bien): JsonResponse
    {
        $this->asegurarDisponibleVenta($bien);

        return $this->successResponse($this->toPublicArray($bien->load(['fotos', 'agencia:id,empresa_id,nombre', 'empresa:id,nombre'])));
    }

    public function interes(StoreInteresBienRequest $request, Bien $bien): JsonResponse
    {
        $this->asegurarDisponibleVenta($bien);

        $interes = InteresBien::query()->create([
            'bien_id' => $bien->id,
            'empresa_id' => $bien->empresa_id,
            'agencia_id' => $bien->agencia_id,
            ...$request->validated(),
        ]);

        $this->notificaciones->enviar($this->controladoresDe($bien), new InteresBienNotification($interes));

        return $this->successResponse(null, 'Gracias, en breve te contactaremos.', 201);
    }

    private function asegurarDisponibleVenta(Bien $bien): void
    {
        abort_unless($bien->estado === 'disponible_venta', 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function toPublicArray(Bien $bien): array
    {
        return [
            'id' => $bien->id,
            'tipo' => $bien->tipo,
            'nombre' => $bien->nombre,
            'marca' => $bien->marca,
            'modelo' => $bien->modelo,
            'valorizacion' => $bien->valorizacion,
            'puntaje' => $bien->puntaje,
            'foto_cliente_producto_url' => $bien->foto_cliente_producto_url,
            'video_url' => $bien->video_url,
            'fotos' => $bien->fotos->map(fn ($foto): array => ['id' => $foto->id, 'url' => $foto->url, 'orden' => $foto->orden])->values(),
            'agencia' => $bien->agencia ? ['id' => $bien->agencia->id, 'nombre' => $bien->agencia->nombre] : null,
            'empresa' => $bien->empresa ? ['id' => $bien->empresa->id, 'nombre' => $bien->empresa->nombre] : null,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function controladoresDe(Bien $bien): Collection
    {
        return User::role('administrador_general')->where('empresa_id', $bien->empresa_id)->get()
            ->merge(User::role('administrador_agencia')->where('agencia_id', $bien->agencia_id)->get());
    }
}
