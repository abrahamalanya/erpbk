<?php

namespace App\Modules\CreditoPrendario\Policies;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Services\CreditoPrendarioHierarchyService;
use App\Modules\Usuario\Models\User;

class CreditoPrendarioPolicy
{
    public function __construct(private readonly CreditoPrendarioHierarchyService $hierarchy) {}

    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('creditos_prendarios.ver');
    }

    public function view(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.ver') && $this->hierarchy->puedeVer($user, $credito);
    }

    public function create(User $user): bool
    {
        return $user->can('creditos_prendarios.crear');
    }

    public function aprobar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.aprobar') && $this->hierarchy->puedeAprobar($user, $credito);
    }

    public function rechazar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.rechazar') && $this->hierarchy->puedeAprobar($user, $credito);
    }

    public function firmar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.firmar') && $this->hierarchy->puedeVer($user, $credito);
    }

    public function refrendar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.refrendar') && $this->hierarchy->puedeVer($user, $credito);
    }

    public function liquidar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.liquidar') && $this->hierarchy->puedeVer($user, $credito);
    }
}
