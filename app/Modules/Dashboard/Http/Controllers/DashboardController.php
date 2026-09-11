<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Services\ClienteHierarchyService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ClienteHierarchyService $hierarchy) {}

    /**
     * Clientes con dirección georreferenciada (casa), para el mapa del panel
     * principal — mismo alcance de visibilidad que el listado de clientes
     * (clientes.ver + ClienteHierarchyService::visibleQuery). Cada uno lleva
     * si tiene o no un crédito activo/vencido y, de tenerlo, la fecha de
     * vencimiento del más urgente (el de vencimiento más próximo), para el
     * popup del marcador.
     */
    public function mapaClientes(): JsonResponse
    {
        Gate::authorize('viewAny', Cliente::class);

        $query = Cliente::query()
            ->where('estado', 'activo')
            ->whereNotNull('latitud')
            ->whereNotNull('longitud')
            ->with(['creditos' => fn ($q) => $q->whereIn('estado', ['activo', 'vencido'])->orderBy('fecha_vencimiento')]);

        $query = $this->hierarchy->visibleQuery($query, request()->user());

        $clientes = $query->get()->map(function (Cliente $cliente): array {
            $creditoActivo = $cliente->creditos->first();

            return [
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'apellido' => $cliente->apellido,
                'tipo_documento' => $cliente->tipo_documento,
                'numero_documento' => $cliente->numero_documento,
                'direccion' => $cliente->direccion,
                'referencia' => $cliente->referencia,
                'latitud' => (float) $cliente->latitud,
                'longitud' => (float) $cliente->longitud,
                'tiene_credito_activo' => $creditoActivo !== null,
                'fecha_vencimiento_credito' => $creditoActivo?->fecha_vencimiento?->toDateString(),
            ];
        });

        return $this->successResponse($clientes->values());
    }
}
