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

    /**
     * "Ingresar como". El rol del target debe estar dentro del techo
     * estructural que el actor puede asignar (excluye estructuralmente
     * impersonar a otro `administrador_general` o a `sistemas`). Las
     * invariantes de negocio (auto-impersonación, target inactivo,
     * anti-anidamiento, etc.) viven en ImpersonacionService — antes()
     * bypassa esta ability para `sistemas`, así que esta Policy sola no
     * basta para cubrirlas.
     */
    public function impersonar(User $user, User $target): bool
    {
        return $user->can('usuarios.impersonar')
            && array_intersect($target->getRoleNames()->all(), $this->hierarchy->assignableRoles($user)) !== []
            && $this->hierarchy->canManage($user, $target);
    }
}
