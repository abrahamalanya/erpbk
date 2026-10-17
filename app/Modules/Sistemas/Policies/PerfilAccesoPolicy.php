<?php

namespace App\Modules\Sistemas\Policies;

use App\Modules\Usuario\Models\User;

class PerfilAccesoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('sistemas') || $user->can('usuarios.crear') || $user->can('usuarios.editar');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('sistemas');
    }

    public function update(User $user): bool
    {
        return $user->hasRole('sistemas');
    }
}
