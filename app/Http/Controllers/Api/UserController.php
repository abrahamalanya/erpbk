<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\UserHierarchyService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserHierarchyService $hierarchy) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $actor = request()->user();
        $query = User::query()->with(['roles', 'empresa', 'agencia']);

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        }

        return $this->successResponse($query->paginate(15));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();
        $actor = $request->user();
        $role = $data['role'];

        $user = User::query()->create([
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'email' => $data['email'],
            'password' => $data['password'],
            'estado' => $data['estado'] ?? 'activo',
            'empresa_id' => $this->hierarchy->resolveEmpresaId($actor, $role, $data['empresa_id'] ?? null),
            'agencia_id' => $this->hierarchy->resolveAgenciaId($actor, $role, $data['agencia_id'] ?? null),
            'supervisor_id' => $data['supervisor_id'] ?? null,
        ]);

        $user->assignRole($role);

        return $this->successResponse($user->load(['roles', 'empresa', 'agencia']), 'Usuario creado', 201);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return $this->successResponse($user->load(['roles', 'empresa', 'agencia']));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $user->update($request->validated());

        return $this->successResponse($user->load(['roles', 'empresa', 'agencia']), 'Usuario actualizado');
    }

    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        return $this->successResponse(null, 'Usuario eliminado');
    }
}
