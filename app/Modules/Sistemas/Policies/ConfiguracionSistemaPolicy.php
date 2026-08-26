<?php

namespace App\Modules\Sistemas\Policies;

use App\Modules\Usuario\Models\User;

class ConfiguracionSistemaPolicy
{
    /**
     * Determine whether the user can update the branding config. Hardcoded
     * to "sistemas" on purpose, never permission-gated — same reasoning as
     * RolePolicy/PermissionPolicy: this is platform-level, not a business
     * permission that should be delegable.
     */
    public function update(User $user): bool
    {
        return $user->hasRole('sistemas');
    }
}
