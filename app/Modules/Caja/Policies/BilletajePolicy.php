<?php

namespace App\Modules\Caja\Policies;

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Usuario\Models\User;

class BilletajePolicy
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
        return $user->can('billetajes.ver');
    }

    public function view(User $user, Billetaje $billetaje): bool
    {
        if (! $user->can('billetajes.ver')) {
            return false;
        }

        if ($billetaje->solicitado_por === $user->id) {
            return true;
        }

        return $this->hierarchy->puedeControlarBoveda($user, $billetaje->boveda);
    }

    public function create(User $user): bool
    {
        return $user->can('billetajes.crear');
    }

    public function aprobar(User $user, Billetaje $billetaje): bool
    {
        return $user->can('billetajes.aprobar') && $this->hierarchy->puedeControlarBoveda($user, $billetaje->boveda);
    }

    public function rechazar(User $user, Billetaje $billetaje): bool
    {
        return $user->can('billetajes.rechazar') && $this->hierarchy->puedeControlarBoveda($user, $billetaje->boveda);
    }
}
