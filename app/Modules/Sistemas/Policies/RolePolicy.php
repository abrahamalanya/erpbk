<?php

namespace App\Modules\Sistemas\Policies;

use App\Modules\Sistemas\Models\Role;
use App\Modules\Usuario\Models\User;

class RolePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('sistemas');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Role $role): bool
    {
        return $user->hasRole('sistemas');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Role $role): bool
    {
        // el set de permisos de "sistemas" es un invariante del sistema, no editable via API
        return $user->hasRole('sistemas') && $role->name !== 'sistemas';
    }
}
