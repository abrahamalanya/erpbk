<?php

namespace App\Modules\Ubicacion\Policies;

use App\Modules\Usuario\Models\User;

class UbicacionAsesorPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    /**
     * Determine whether the user can view the live asesores map.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('ubicaciones_asesores.ver');
    }
}
