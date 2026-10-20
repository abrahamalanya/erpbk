<?php

namespace App\Modules\Sistemas\Policies;

use App\Modules\Usuario\Models\User;

/**
 * Módulo exclusivo del rol sistemas — ni siquiera parcialmente accesible a
 * otros roles (a diferencia de, p. ej., ModuloPolicy::viewAny).
 */
class ApkVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('sistemas');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('sistemas');
    }

    public function publicar(User $user): bool
    {
        return $user->hasRole('sistemas');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('sistemas');
    }
}
