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

    /**
     * Only the asesor who registered the crédito can subsanar it — NOT the
     * admin who rejected it (confirmed explicitly: they're the ones who
     * asked for the fix, they don't perform it). Deliberately stricter than
     * puedeVer()'s broader visibility scope (which would also let a
     * supervisor or admin trigger this on someone else's behalf).
     */
    public function subsanar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.subsanar') && $credito->registrado_por === $user->id;
    }

    public function desembolsar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.desembolsar') && $this->hierarchy->puedeVer($user, $credito);
    }

    public function refrendar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.refrendar') && $this->hierarchy->puedeVer($user, $credito);
    }

    public function liquidar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.liquidar') && $this->hierarchy->puedeVer($user, $credito);
    }

    /**
     * Same authority as aprobar/rechazar — editing terms (e.g. a custom
     * interest rate for an exclusive client) is an admin decision, not tied
     * to a specific actor the way subsanar() is.
     */
    public function editar(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.editar') && $this->hierarchy->puedeAprobar($user, $credito);
    }

    /**
     * Undoes an accidental aprobar(). Deliberately the same broad authority
     * as aprobar/rechazar (any admin who could approve this crédito can also
     * fix a mistaken approval), not restricted to whoever specifically
     * approved it — confirmed explicitly, unlike subsanar()'s ownership check.
     */
    public function revertirAprobacion(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.revertir_aprobacion') && $this->hierarchy->puedeAprobar($user, $credito);
    }

    /**
     * Same admin-level authority as aprobar/rechazar/editar — deciding to
     * escalate a vencido crédito to the public tienda early (once it's past
     * the configured período de espera) is an admin call, not something the
     * asesor who registered it triggers.
     */
    public function enviarATienda(User $user, CreditoPrendario $credito): bool
    {
        return $user->can('creditos_prendarios.enviar_tienda') && $this->hierarchy->puedeAprobar($user, $credito);
    }
}
