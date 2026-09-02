<?php

namespace App\Modules\CreditoHipotecario\Policies;

use App\Modules\Credito\Services\GarantiaHierarchyService;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\Usuario\Models\User;

class InmueblePolicy
{
    public function __construct(
        private readonly GarantiaHierarchyService $hierarchy,
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('inmuebles.ver');
    }

    public function view(User $user, Inmueble $inmueble): bool
    {
        return $user->can('inmuebles.ver') && $this->hierarchy->canView($user, $inmueble);
    }

    public function create(User $user): bool
    {
        return $user->can('inmuebles.crear');
    }

    public function update(User $user, Inmueble $inmueble): bool
    {
        return $user->can('inmuebles.editar') && $this->hierarchy->canManage($user, $inmueble);
    }
}
