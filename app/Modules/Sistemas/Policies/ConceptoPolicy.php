<?php

namespace App\Modules\Sistemas\Policies;

use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;

class ConceptoPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    /**
     * Every authenticated user can browse the (tenant-scoped) conceptos
     * catalog — needed to populate the concepto dropdown when registering an
     * ingreso/gasto in Caja. TenantScope already restricts which rows are
     * reachable, same pattern as BancoPolicy's global (unscoped) catalog.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Concepto $concepto): bool
    {
        return $concepto->empresa_id === $user->empresa_id;
    }

    /**
     * Mutating the catalog itself stays sistemas-only, same pattern as
     * Banco/Role/Permission — never permission-gated.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Concepto $concepto): bool
    {
        return false;
    }

    public function delete(User $user, Concepto $concepto): bool
    {
        return false;
    }
}
