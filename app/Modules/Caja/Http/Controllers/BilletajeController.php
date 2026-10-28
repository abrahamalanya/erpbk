<?php

namespace App\Modules\Caja\Http\Controllers;

use App\Modules\Caja\Http\Requests\RechazarBilletajeRequest;
use App\Modules\Caja\Http\Requests\StoreBilletajeRequest;
use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Services\BilletajeService;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BilletajeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BilletajeService $billetajeService,
        private readonly CajaBovedaHierarchyService $hierarchy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Billetaje::class);

        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
            'asesor_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $query = Billetaje::query()->with(['boveda', 'solicitadoPor', 'asesor', 'aprobadoPor', 'cliente', 'fotos']);
        $query = $this->hierarchy->billetajesVisibles($query, request()->user());

        // Los aceptados pertenecen al día en que ingresaron a caja; los
        // pendientes, que aún no tienen resolución, al día del envío.
        $fecha = DB::raw('COALESCE(fecha_resolucion, created_at)');
        $query->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->whereDate($fecha, '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->whereDate($fecha, '<=', $hasta))
            ->when($filtros['agencia_id'] ?? null, fn ($q, $agencia) => $q->whereHas('cajaCiclo.caja', fn ($caja) => $caja->where('agencia_id', $agencia)))
            ->when($filtros['asesor_id'] ?? null, fn ($q, $asesor) => $q->where('asesor_id', $asesor));

        $resumen = (clone $query)->where('estado', 'aprobado')->toBase()
            ->selectRaw('COALESCE(SUM(monto), 0) as total, COUNT(*) as cantidad')->first();

        return $this->successResponse([
            ...$query->latest()->paginate(15)->toArray(),
            'resumen' => [
                'total' => bcadd((string) $resumen->total, '0', 2),
                'cantidad' => (int) $resumen->cantidad,
            ],
        ]);
    }

    /**
     * A systems/administrador_general/administrador_agencia sends a billetaje
     * to an asesor. The asesor will only be able to accept or reject it.
     */
    public function store(StoreBilletajeRequest $request): JsonResponse
    {
        Gate::authorize('create', Billetaje::class);

        $asesor = User::query()->findOrFail($request->validated('asesor_id'));

        $billetaje = $this->billetajeService->enviar(
            $request->user(),
            $asesor,
            (string) $request->validated('monto'),
            $request->validated('motivo'),
            $request->validated('medio_egreso', 'efectivo'),
            $request->validated('canal_egreso'),
            $request->validated('cuenta_bancaria_id') !== null ? (int) $request->validated('cuenta_bancaria_id') : null,
            $request->file('comprobante'),
            $request->validated('cliente_id') !== null ? (int) $request->validated('cliente_id') : null,
        );

        return $this->successResponse(
            $billetaje->load(['solicitadoPor', 'asesor', 'boveda', 'cliente', 'fotos']),
            'Billetaje enviado',
            201,
        );
    }

    public function aceptar(Billetaje $billetaje): JsonResponse
    {
        Gate::authorize('aprobar', $billetaje);

        $billetaje = $this->billetajeService->aceptar($billetaje, request()->user());

        return $this->successResponse($billetaje, 'Billetaje aceptado');
    }

    public function rechazar(RechazarBilletajeRequest $request, Billetaje $billetaje): JsonResponse
    {
        Gate::authorize('rechazar', $billetaje);

        $billetaje = $this->billetajeService->rechazar($billetaje, $request->user(), $request->validated('motivo'));

        return $this->successResponse($billetaje, 'Billetaje rechazado');
    }
}
