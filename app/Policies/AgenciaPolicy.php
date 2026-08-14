<?php

namespace App\Policies;

use App\Models\Agencia;
use App\Models\User;

class AgenciaPolicy
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
        return $user->hasAnyRole(['administrador_general', 'secretaria']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Agencia $agencia): bool
    {
        if ($user->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $user->empresa_id === $agencia->empresa_id;
        }

        return $user->agencia_id === $agencia->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('administrador_general');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Agencia $agencia): bool
    {
        return $user->hasRole('administrador_general') && $user->empresa_id === $agencia->empresa_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Agencia $agencia): bool
    {
        return $user->hasRole('administrador_general') && $user->empresa_id === $agencia->empresa_id;
    }
}
