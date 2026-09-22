<?php

namespace App\Modules\Venta\Policies;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;

class ConfiguracionVentaPolicy
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
        return $user->can('configuraciones_venta.ver');
    }

    /**
     * $agencia is null when the target is the empresa-wide default row.
     */
    public function update(User $user, ?Agencia $agencia): bool
    {
        if (! $user->can('configuraciones_venta.editar')) {
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

    /**
     * Misma autoridad que update().
     */
    public function delete(User $user, ?Agencia $agencia): bool
    {
        if (! $user->can('configuraciones_venta.eliminar')) {
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
