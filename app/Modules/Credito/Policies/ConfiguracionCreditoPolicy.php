<?php

namespace App\Modules\Credito\Policies;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;

class ConfiguracionCreditoPolicy
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

    /**
     * Misma autoridad que update() — el asesor/supervisor no toca esto, solo
     * administradores. $agencia es null cuando el objetivo es la fila default
     * de toda la empresa: solo administrador_general puede borrarla (deja a
     * la empresa sin configuración resoluble para ese tipo hasta que se
     * registre una nueva), igual que solo él puede editarla.
     */
    public function delete(User $user, ?Agencia $agencia): bool
    {
        if (! $user->can('configuraciones_credito_prendario.eliminar')) {
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
