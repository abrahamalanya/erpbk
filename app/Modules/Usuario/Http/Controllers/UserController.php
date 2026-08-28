<?php

namespace App\Modules\Usuario\Http\Controllers;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Http\Requests\StoreUserRequest;
use App\Modules\Usuario\Http\Requests\UpdateUserRequest;
use App\Modules\Usuario\Models\User;
use App\Modules\Usuario\Services\UserHierarchyService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ConsultaDniService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserHierarchyService $hierarchy,
        private readonly ConsultaDniService $consultaDni,
    ) {}

    public function consultarDni(string $dni): JsonResponse
    {
        Gate::authorize('create', User::class);

        return $this->successResponse($this->consultaDni->consultar($dni));
    }

    /**
     * The roles the authenticated user is allowed to assign when creating or
     * editing another user, so the frontend selector never has to hardcode
     * the assignment hierarchy.
     */
    public function rolesAsignables(): JsonResponse
    {
        Gate::authorize('create', User::class);

        return $this->successResponse($this->hierarchy->assignableRoles(request()->user()));
    }

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $actor = request()->user();
        $query = User::query()->with(['roles', 'empresa', 'agencia']);

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        }

        // La empresa solo es un filtro real para "sistemas" — cualquier otro
        // rol ya está limitado a su propia empresa por el TenantScope.
        if ($actor->hasRole('sistemas') && request()->filled('empresa_id')) {
            $query->where('empresa_id', request()->integer('empresa_id'));
        }

        if (request()->filled('agencia_id')) {
            $query->where('agencia_id', request()->integer('agencia_id'));
        }

        if (request()->filled('role')) {
            $role = request()->string('role');
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if (request()->filled('dni')) {
            $query->where('dni', 'like', '%'.request()->string('dni').'%');
        }

        if (request()->filled('estado')) {
            $query->where('estado', request()->string('estado'));
        }

        if (request()->filled('nombre')) {
            $nombre = request()->string('nombre');
            $query->where(fn ($q) => $q->where('nombre', 'like', "%{$nombre}%")
                ->orWhere('apellido', 'like', "%{$nombre}%"));
        }

        return $this->successResponse($query->paginate(15));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();
        $actor = $request->user();
        $roles = array_values(array_unique($data['roles']));
        $empresaId = $this->hierarchy->resolveEmpresaId($actor, $data['empresa_id'] ?? null);

        $user = User::query()->create([
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'dni' => $data['dni'],
            'telefono' => $data['telefono'] ?? null,
            'email' => $data['email'] ?? $this->componerEmail($empresaId, $data['usuario'] ?? $data['dni']),
            'password' => $data['password'] ?? $data['dni'],
            'estado' => $data['estado'] ?? 'activo',
            'empresa_id' => $empresaId,
            'agencia_id' => $this->hierarchy->resolveAgenciaId($actor, $roles, $data['agencia_id'] ?? null),
            'supervisor_id' => $data['supervisor_id'] ?? null,
        ]);

        $user->syncRoles($roles);

        return $this->successResponse($user->load(['roles', 'empresa', 'agencia']), 'Usuario creado', 201);
    }

    /**
     * Builds the email from the empresa's mail prefix + the given local
     * part (falls back to the local part alone if the empresa has no
     * prefijo — StoreUserRequest already requires an explicit email in
     * that case, so this branch is unreachable in practice).
     */
    private function componerEmail(?int $empresaId, string $usuario): string
    {
        $prefijo = $empresaId ? Empresa::find($empresaId)?->prefijo : null;

        return $prefijo ? "{$usuario}@{$prefijo}" : $usuario;
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return $this->successResponse($user->load(['roles', 'empresa', 'agencia']));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validated();
        $roles = $data['roles'] ?? null;
        unset($data['roles']);

        $user->update($data);

        if ($roles !== null) {
            $user->syncRoles(array_values(array_unique($roles)));
        }

        return $this->successResponse($user->load(['roles', 'empresa', 'agencia']), 'Usuario actualizado');
    }

    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        return $this->successResponse(null, 'Usuario eliminado');
    }
}
