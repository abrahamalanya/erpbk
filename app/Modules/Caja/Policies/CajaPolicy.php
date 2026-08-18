<?php

namespace App\Modules\Caja\Policies;

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Usuario\Models\User;

class CajaPolicy
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
        return $user->can('cajas.ver');
    }

    public function view(User $user, Caja $caja): bool
    {
        if (! $user->can('cajas.ver')) {
            return false;
        }

        if ($caja->user_id === $user->id) {
            return true;
        }

        if ($user->hasRole('administrador_agencia')) {
            return $caja->agencia_id === $user->agencia_id;
        }

        if ($user->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $caja->empresa_id === $user->empresa_id;
        }

        return false;
    }

    public function aperturar(User $user): bool
    {
        return $user->can('cajas.aperturar');
    }

    public function cerrar(User $user): bool
    {
        return $user->can('cajas.cerrar');
    }

    public function cerrarForzado(User $user, Caja $caja): bool
    {
        return $user->can('cajas.cerrar_forzado') && $this->hierarchy->puedeForzarCierre($user, $caja);
    }
}
