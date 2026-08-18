<?php

namespace App\Modules\CreditoPrendario\Policies;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;

class ConfiguracionCreditoPrendarioPolicy
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
        return $user->can('configuraciones_credito_prendario.ver');
    }

    /**
     * $agencia is null when the target is the empresa-wide default row.
     */
    public function update(User $user, ?Agencia $agencia): bool
    {
        if (! $user->can('configuraciones_credito_prendario.editar')) {
            return false;
        }

        if ($agencia === null) {
            return $user->hasRole('administrador_general');
        }

        if ($user->hasRole('administrador_general')) {
            return $user->empresa_id === $agencia->empresa_id;
        }

        if ($user->hasRole('administrador_agencia')) {
            return $user->agencia_id === $agencia->id;
        }

        return false;
    }
}
