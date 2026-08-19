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

    public function cerrar(User $user, Boveda $boveda): bool
    {
        return $user->can('bovedas.cerrar') && $this->hierarchy->puedeControlarBoveda($user, $boveda);
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

    public function reabrir(User $user, Boveda $boveda): bool
    {
        return $user->can('bovedas.reabrir') && $this->hierarchy->puedeControlarBoveda($user, $boveda);
    }
}
