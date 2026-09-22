<?php

namespace App\Modules\Tienda\Policies;

use App\Modules\Tienda\Models\InteresArticulo;
use App\Modules\Usuario\Models\User;

class InteresArticuloPolicy
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
        return $user->can('intereses_tienda.ver');
    }

    public function view(User $user, InteresArticulo $interes): bool
    {
        return $user->can('intereses_tienda.ver') && $this->mismaAgencia($user, $interes);
    }

    public function atender(User $user, InteresArticulo $interes): bool
    {
        return $user->can('intereses_tienda.atender') && $this->mismaAgencia($user, $interes);
    }

    public function delete(User $user, InteresArticulo $interes): bool
    {
        return $user->can('intereses_tienda.atender') && $this->mismaAgencia($user, $interes);
    }

    private function mismaAgencia(User $user, InteresArticulo $interes): bool
    {
        if ($user->hasRole('administrador_general')) {
            return $user->empresa_id === $interes->empresa_id;
        }

        if ($user->hasAnyRole(['administrador_agencia', 'asesor'])) {
            return $user->agencia_id === $interes->agencia_id;
        }

        return false;
    }
}
