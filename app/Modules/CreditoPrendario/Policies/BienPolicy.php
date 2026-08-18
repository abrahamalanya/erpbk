<?php

namespace App\Modules\CreditoPrendario\Policies;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Usuario\Models\User;

class BienPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('bienes.ver');
    }

    public function view(User $user, Bien $bien): bool
    {
        if (! $user->can('bienes.ver')) {
            return false;
        }

        if ($user->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $user->empresa_id === $bien->empresa_id;
        }

        if ($user->hasRole('administrador_agencia')) {
            return $user->agencia_id === $bien->agencia_id;
        }

        return $bien->registrado_por === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('bienes.crear');
    }
}
