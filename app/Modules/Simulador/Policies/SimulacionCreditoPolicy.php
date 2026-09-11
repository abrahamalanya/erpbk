<?php

namespace App\Modules\Simulador\Policies;

use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Simulador\Models\SimulacionCredito;
use App\Modules\Usuario\Models\User;

class SimulacionCreditoPolicy
{
    public function __construct(private readonly CreditoHierarchyService $hierarchy) {}

    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('simulaciones_credito.ver');
    }

    /**
     * Misma jerarquía de visibilidad que Credito (visibleQuery es genérico
     * sobre agencia_id/registrado_por, no atado al modelo Credito).
     */
    public function view(User $user, SimulacionCredito $simulacion): bool
    {
        return $user->can('simulaciones_credito.ver')
            && $this->hierarchy->visibleQuery(SimulacionCredito::query(), $user)->whereKey($simulacion->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('simulaciones_credito.crear');
    }

    /**
     * Solo quien la registró puede borrarla — no es un documento oficial
     * como un crédito, así que no amerita la autoridad de nivel admin de
     * CreditoPolicy::delete().
     */
    public function delete(User $user, SimulacionCredito $simulacion): bool
    {
        return $user->can('simulaciones_credito.eliminar') && $simulacion->registrado_por === $user->id;
    }
}
