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

        if ($billetaje->asesor_id === $user->id) {
            return true;
        }

        return $this->hierarchy->puedeControlarBilletaje($user, $billetaje);
    }

    public function create(User $user): bool
    {
        return $user->can('billetajes.crear');
    }

    /**
     * Aceptar: only the asesor the billetaje was enviado to — the
     * administrador that enviado it does NOT resolve it.
     */
    public function aprobar(User $user, Billetaje $billetaje): bool
    {
        return $user->can('billetajes.aprobar') && $billetaje->asesor_id === $user->id;
    }

    public function rechazar(User $user, Billetaje $billetaje): bool
    {
        return $user->can('billetajes.rechazar') && $billetaje->asesor_id === $user->id;
    }
}
