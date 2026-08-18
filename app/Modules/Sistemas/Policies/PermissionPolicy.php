<?php

namespace App\Modules\Sistemas\Policies;

use App\Modules\Usuario\Models\User;

class PermissionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('sistemas');
    }
}
