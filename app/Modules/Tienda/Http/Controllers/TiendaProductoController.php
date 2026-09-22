<?php

namespace App\Modules\Tienda\Http\Controllers;

use App\Modules\Credito\Services\GarantiaHierarchyService;
use App\Modules\Tienda\Http\Requests\UpdateTiendaProductoRequest;
use App\Modules\Tienda\Services\TiendaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Configuración admin de los productos de la tienda virtual: precio,
 * oferta, estado y retiro (sin borrar el registro — confirmado con el
 * usuario). Reusa la Policy propia de cada tipo de garantía
 * (bienes.editar / vehiculos.editar / inmuebles.editar vía Gate::authorize
 * ('update', $articulo)) en vez de crear permisos nuevos.
 */
class TiendaProductoController extends Controller
{
    use ApiResponse;

    /**
     * @var array<string, string>
     */
    private const PERMISO_VER = [
        'bien' => 'bienes.ver',
        'vehiculo' => 'vehiculos.ver',
        'inmueble' => 'inmuebles.ver',
    ];

    public function __construct(
        private readonly TiendaService $tienda,
        private readonly GarantiaHierarchyService $hierarchy,
    ) {}

    /**
     * Lista los artículos publicados (disponible_venta) o retirados
     * (retirado_venta) — a diferencia del catálogo público, incluye los
     * retirados para poder re-publicarlos.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $tipoFiltro = $request->filled('tipo') ? $request->string('tipo')->value() : null;

        $tipos = collect($tipoFiltro ? [$tipoFiltro] : $this->tienda->tiposDisponibles())
            ->filter(fn (string $t): bool => $actor->can(self::PERMISO_VER[$t] ?? 'nunca'))
            ->values();

        abort_if($tipos->isEmpty(), 403);

        $articulos = $tipos->flatMap(function (string $tipo) use ($actor): Collection {
            $modelo = $this->tienda->modeloDe($tipo);

            if ($modelo === null) {
                return collect();
            }

            $query = $modelo::query()
                ->whereIn('estado', ['disponible_venta', 'retirado_venta'])
                ->with(['fotos', 'agencia:id,empresa_id,nombre', 'empresa:id,nombre']);

            return $this->hierarchy->visibleQuery($query, $actor)->get();
        })->sortByDesc('created_at')->values();

        $page = max(1, $request->integer('page', 1));
        $perPage = 15;
        $slice = $articulos->forPage($page, $perPage)->map(fn (Model $a): array => $this->tienda->datosPublicos($a))->values();

        return $this->successResponse(new LengthAwarePaginator($slice, $articulos->count(), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]));
    }

    public function update(UpdateTiendaProductoRequest $request, string $tipo, int $id): JsonResponse
    {
        $articulo = $this->tienda->resolverCualquiera($tipo, $id);
        abort_if($articulo === null, 404);

        Gate::authorize('update', $articulo);

        $articulo = $this->tienda->actualizar($articulo, $request->validated());

        return $this->successResponse($this->tienda->datosPublicos($articulo), 'Producto actualizado');
    }

    public function retirar(string $tipo, int $id): JsonResponse
    {
        $articulo = $this->tienda->resolverCualquiera($tipo, $id);
        abort_if($articulo === null, 404);

        Gate::authorize('update', $articulo);

        $articulo = $this->tienda->retirar($articulo);

        return $this->successResponse($this->tienda->datosPublicos($articulo), 'Producto retirado de la tienda');
    }
}
