<?php

namespace App\Modules\Usuario\Policies;

use App\Modules\Usuario\Models\User;
use App\Modules\Usuario\Services\UserHierarchyService;

class UserPolicy
{
    public function __construct(private readonly UserHierarchyService $hierarchy) {}

    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('usuarios.ver');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $target): bool
    {
        return $user->can('usuarios.ver') && $this->hierarchy->canManage($user, $target);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('usuarios.crear');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $target): bool
    {
        return $user->can('usuarios.editar') && $this->hierarchy->canManage($user, $target);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->can('usuarios.eliminar')
            && $this->hierarchy->canManage($user, $target)
            && $user->id !== $target->id;
    }
}
