<?php

namespace App\Modules\Caja\Policies;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Usuario\Models\User;

class BovedaPolicy
{
    public function __construct(private readonly CajaBovedaHierarchyService $hierarchy) {}

    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('bovedas.ver');
    }

    public function view(User $user, Boveda $boveda): bool
    {
        if (! $user->can('bovedas.ver')) {
            return false;
        }

        if ($user->hasRole('administrador_agencia')) {
            return $boveda->agencia_id === $user->agencia_id;
        }

        if ($user->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $boveda->empresa_id === $user->empresa_id;
        }

        return false;
    }

    /**
     * administrador_general controla también la bóveda de cualquier agencia
     * de su empresa (no solo la principal) — misma autoridad de empresa
     * completa que ya tiene para billetajes/cuentas bancarias. No se toca
     * CajaBovedaHierarchyService::puedeControlarBoveda() para esto: esa
     * también la usa puedeForzarCierre(), que por separado ya le da a
     * administrador_general autoridad de empresa completa para forzar el
     * cierre de cualquier caja debajo.
     */
    public function cerrar(User $user, Boveda $boveda): bool
    {
        if (! $user->can('bovedas.cerrar')) {
            return false;
        }

        if ($user->hasRole('administrador_general')) {
            return $user->empresa_id === $boveda->empresa_id;
        }

        return $this->hierarchy->puedeControlarBoveda($user, $boveda);
    }

    public function aperturar(User $user, Boveda $boveda): bool
    {
        return $user->can('bovedas.aperturar')
            && $boveda->tipo === 'principal'
            && $user->hasRole('administrador_general')
            && $user->empresa_id === $boveda->empresa_id;
    }

    /**
     * Works for both the principal bóveda (external capital injection) and
     * any agencia bóveda in the same empresa (traspaso from the principal)
     * — always driven by administrador_general, who controls the principal.
     */
    public function inyectar(User $user, Boveda $boveda): bool
    {
        return $user->can('bovedas.inyectar')
            && $user->hasRole('administrador_general')
            && $user->empresa_id === $boveda->empresa_id;
    }

    /**
     * Espejo de inyectar(): retiro externo de la principal o devolución a la
     * principal desde una bóveda de agencia de la misma empresa.
     */
    public function retirar(User $user, Boveda $boveda): bool
    {
        return $user->can('bovedas.retirar')
            && $user->hasRole('administrador_general')
            && $user->empresa_id === $boveda->empresa_id;
    }

    /** Mismo alcance que cerrar(): administrador_general también reabre la de cualquier agencia. */
    public function reabrir(User $user, Boveda $boveda): bool
    {
        if (! $user->can('bovedas.reabrir')) {
            return false;
        }

        if ($user->hasRole('administrador_general')) {
            return $user->empresa_id === $boveda->empresa_id;
        }

        return $this->hierarchy->puedeControlarBoveda($user, $boveda);
    }
}
