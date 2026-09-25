<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Sistemas\Http\Requests\ListPermisosTemporalesRequest;
use App\Modules\Sistemas\Http\Requests\RevocarPermisoTemporalRequest;
use App\Modules\Sistemas\Http\Requests\StorePermisoTemporalRequest;
use App\Modules\Sistemas\Models\PermisoTemporal;
use App\Modules\Sistemas\Services\PermisoTemporalService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PermisoTemporalController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PermisoTemporalService $permisos) {}

    public function index(ListPermisosTemporalesRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', PermisoTemporal::class);

        $filtros = $request->validated();

        return $this->successResponse($this->permisos->listar(
            $request->user(),
            $filtros['estado'] ?? 'todos',
            (int) ($filtros['page'] ?? 1),
            min((int) ($filtros['per_page'] ?? 15), 100),
        ));
    }

    public function store(StorePermisoTemporalRequest $request, Cliente $cliente): JsonResponse
    {
        Gate::authorize('create', PermisoTemporal::class);

        $permiso = $this->permisos->conceder(
            $request->user(),
            $cliente,
            (string) $request->validated('motivo'),
        );

        return $this->successResponse(
            $permiso->load([
                'cliente',
                'usuario',
                'concedidoPorUsuario' => fn ($query) => $query->withoutGlobalScopes(),
            ]),
            'Permiso temporal de edición concedido',
            201,
        );
    }

    public function destroy(RevocarPermisoTemporalRequest $request, PermisoTemporal $permisoTemporal): JsonResponse
    {
        Gate::authorize('delete', $permisoTemporal);

        $permiso = $this->permisos->revocar(
            $request->user(),
            $permisoTemporal,
            (string) $request->validated('motivo'),
        );

        return $this->successResponse(
            $permiso->load([
                'cliente',
                'usuario',
                'concedidoPorUsuario' => fn ($query) => $query->withoutGlobalScopes(),
                'revocadoPorUsuario' => fn ($query) => $query->withoutGlobalScopes(),
            ]),
            'Permiso temporal revocado',
        );
    }
}
