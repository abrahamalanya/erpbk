<?php

namespace App\Modules\Sistemas\Policies;

use App\Modules\Sistemas\Models\PermisoTemporal;
use App\Modules\Usuario\Models\User;

class PermisoTemporalPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $this->esGestor($user);
    }

    public function create(User $user): bool
    {
        return $this->esGestor($user);
    }

    public function delete(User $user, PermisoTemporal $permisoTemporal): bool
    {
        return $this->esGestor($user) && $user->empresa_id === $permisoTemporal->empresa_id;
    }

    private function esGestor(User $user): bool
    {
        return $user->hasRole('administrador_general') && $user->can('gestion.permisos_temporales');
    }
}
