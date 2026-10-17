<?php

namespace App\Modules\Sistemas\Policies;

use App\Modules\Usuario\Models\User;

class ModuloPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('sistemas') || $user->can('usuarios.crear') || $user->can('usuarios.editar');
    }
}
