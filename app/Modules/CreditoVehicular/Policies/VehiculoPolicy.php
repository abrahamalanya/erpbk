<?php

namespace App\Modules\CreditoVehicular\Policies;

use App\Modules\Credito\Services\GarantiaHierarchyService;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Usuario\Models\User;

class VehiculoPolicy
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
        return $user->can('vehiculos.ver');
    }

    public function view(User $user, Vehiculo $vehiculo): bool
    {
        return $user->can('vehiculos.ver') && $this->hierarchy->canView($user, $vehiculo);
    }

    public function create(User $user): bool
    {
        return $user->can('vehiculos.crear');
    }

    public function update(User $user, Vehiculo $vehiculo): bool
    {
        return $user->can('vehiculos.editar') && $this->hierarchy->canManage($user, $vehiculo);
    }
}
