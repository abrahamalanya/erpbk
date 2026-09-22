<?php

namespace App\Modules\Venta\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Tienda\Services\TiendaService;
use App\Modules\Venta\Http\Requests\AbonarVentaRequest;
use App\Modules\Venta\Http\Requests\PagarCuotaVentaRequest;
use App\Modules\Venta\Http\Requests\StoreVentaRequest;
use App\Modules\Venta\Models\CuotaVenta;
use App\Modules\Venta\Models\DocumentoVenta;
use App\Modules\Venta\Models\Venta;
use App\Modules\Venta\Services\DocumentoVentaService;
use App\Modules\Venta\Services\VentaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class VentaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly VentaService $ventaService,
        private readonly DocumentoVentaService $documentoService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Venta::class);

        $actor = $request->user();
        $query = Venta::query()->with(['cliente', 'articulo', 'vendidoPor'])->latest();

        if (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        } elseif ($actor->hasRole('asesor')) {
            $query->where('vendido_por', $actor->id);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->string('estado'));
        }

        if ($request->filled('forma_venta')) {
            $query->where('forma_venta', $request->string('forma_venta'));
        }

        if ($request->filled('cliente_id')) {
            $query->where('cliente_id', $request->integer('cliente_id'));
        }

        return $this->successResponse($query->paginate(20));
    }

    public function show(Venta $venta): JsonResponse
    {
        Gate::authorize('view', $venta);

        return $this->successResponse($venta->load(['cliente', 'articulo', 'vendidoPor', 'cuotas', 'pagos', 'documentos']));
    }

    public function store(StoreVentaRequest $request, TiendaService $tienda): JsonResponse
    {
        Gate::authorize('create', Venta::class);

        $data = $request->validated();

        $articulo = $tienda->resolver($data['tipo'], $data['articulo_id']);
        abort_if($articulo === null, 404, 'Este artículo no está disponible en la tienda.');

        $cliente = Cliente::query()->findOrFail($data['cliente_id']);

        $venta = $this->ventaService->crear($request->user(), $cliente, $articulo, $data);

        return $this->successResponse($venta, 'Venta registrada', 201);
    }

    public function pagarCuota(PagarCuotaVentaRequest $request, Venta $venta, CuotaVenta $cuota): JsonResponse
    {
        Gate::authorize('cobrar', $venta);

        $data = $request->validated();

        $venta = $this->ventaService->pagarCuota($venta, $cuota, $request->user(), (string) $data['monto'], $data['medio']);

        return $this->successResponse($venta, 'Pago registrado');
    }

    public function abonar(AbonarVentaRequest $request, Venta $venta): JsonResponse
    {
        Gate::authorize('cobrar', $venta);

        $data = $request->validated();

        $venta = $this->ventaService->abonar($venta, $request->user(), (string) $data['monto'], $data['medio']);

        return $this->successResponse($venta, 'Abono registrado');
    }

    public function cancelar(Venta $venta): JsonResponse
    {
        Gate::authorize('cancelar', $venta);

        $venta = $this->ventaService->cancelar($venta, request()->user());

        return $this->successResponse($venta, 'Venta cancelada; el artículo vuelve a la tienda');
    }

    public function documento(Venta $venta, DocumentoVenta $documento): Response
    {
        Gate::authorize('view', $venta);

        abort_unless($documento->venta_id === $venta->id, 404);

        return $this->documentoService->renderizar($documento);
    }
}
