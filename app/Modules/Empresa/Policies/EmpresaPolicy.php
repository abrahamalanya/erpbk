<?php

namespace App\Modules\Empresa\Policies;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;

class EmpresaPolicy
{
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
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Empresa $empresa): bool
    {
        return $user->empresa_id === $empresa->id && $user->can('empresas.ver');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Empresa $empresa): bool
    {
        return $user->empresa_id === $empresa->id && $user->can('empresas.editar');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Empresa $empresa): bool
    {
        return false;
    }
}
