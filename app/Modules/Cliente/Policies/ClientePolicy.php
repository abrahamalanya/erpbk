<?php

namespace App\Modules\Cliente\Policies;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Services\ClienteHierarchyService;
use App\Modules\Usuario\Models\User;

class ClientePolicy
{
    public function __construct(private readonly ClienteHierarchyService $hierarchy) {}

    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('clientes.ver');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Cliente $cliente): bool
    {
        return $user->can('clientes.ver') && $this->hierarchy->canView($user, $cliente);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('clientes.crear');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Cliente $cliente): bool
    {
        return $user->can('clientes.editar') && $this->hierarchy->canManage($user, $cliente);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Cliente $cliente): bool
    {
        return $user->can('clientes.eliminar') && $this->hierarchy->canManage($user, $cliente);
    }

    /**
     * $asesorId arrives via Gate::authorize('asignar', [$cliente, $asesorId]).
     */
    public function asignar(User $user, Cliente $cliente, int $asesorId): bool
    {
        if (! $user->can('clientes.asignar') || ! $user->hasRole('supervisor')) {
            return false;
        }

        if ($cliente->agencia_id !== $user->agencia_id) {
            return false;
        }

        $asesor = User::query()->find($asesorId);

        return $asesor !== null && $asesor->hasRole('asesor') && $asesor->supervisor_id === $user->id;
    }
}
