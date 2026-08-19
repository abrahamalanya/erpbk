<?php

namespace App\Modules\CreditoPrendario\Policies;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Services\BienHierarchyService;
use App\Modules\Usuario\Models\User;

class BienPolicy
{
    public function __construct(
        private readonly BienHierarchyService $hierarchy,
    ) {}

    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('bienes.ver');
    }

    public function view(User $user, Bien $bien): bool
    {
        return $user->can('bienes.ver') && $this->hierarchy->canView($user, $bien);
    }

    public function create(User $user): bool
    {
        return $user->can('bienes.crear');
    }

    public function update(User $user, Bien $bien): bool
    {
        return $user->can('bienes.editar') && $this->hierarchy->canManage($user, $bien);
    }
}
