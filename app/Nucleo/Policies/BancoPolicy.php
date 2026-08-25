<?php

namespace App\Nucleo\Policies;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Models\Banco;

class BancoPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    /**
     * Every authenticated user can browse the (global, platform-wide) banks
     * catalog — it's a plain reference list needed to populate a dropdown
     * when creating a cuenta bancaria, no tenant/role scoping applies.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Banco $banco): bool
    {
        return true;
    }

    /**
     * Mutating the catalog itself stays sistemas-only, same pattern as
     * Role/Permission — never permission-gated, to avoid self-escalation.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Banco $banco): bool
    {
        return false;
    }

    public function delete(User $user, Banco $banco): bool
    {
        return false;
    }
}
