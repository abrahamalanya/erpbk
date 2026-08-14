<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Models\Role;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        return $this->successResponse(Role::query()->with('permissions')->get());
    }

    public function show(Role $role): JsonResponse
    {
        Gate::authorize('view', $role);

        return $this->successResponse($role->load('permissions'));
    }

    public function update(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        Gate::authorize('update', $role);

        $role->syncPermissions($request->validated('permissions'));

        return $this->successResponse($role->load('permissions'), 'Permisos actualizados');
    }
}
