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
     * El asesor siempre debe pertenecer a la misma agencia del cliente (no
     * se mueve al cliente de agencia aquí, solo se le cambia el asesor); el
     * alcance de QUÉ agencias/asesores puede tocar cada rol es el mismo
     * patrón de canView()/canManage(): administrador_general a nivel
     * empresa, administrador_agencia fijo a la suya, supervisor fijo a la
     * suya y solo con sus propios subordinados (sin ampliar, confirmado
     * explícitamente).
     */
    public function asignar(User $user, Cliente $cliente, int $asesorId): bool
    {
        if (! $user->can('clientes.asignar')) {
            return false;
        }

        $asesor = User::query()->find($asesorId);

        if (! $asesor || ! $asesor->hasRole('asesor') || $asesor->agencia_id !== $cliente->agencia_id) {
            return false;
        }

        if ($user->hasRole('administrador_general')) {
            return $user->empresa_id === $cliente->empresa_id;
        }

        if ($user->hasRole('administrador_agencia')) {
            return $user->agencia_id === $cliente->agencia_id;
        }

        if ($user->hasRole('supervisor')) {
            return $user->agencia_id === $cliente->agencia_id && $asesor->supervisor_id === $user->id;
        }

        return false;
    }
}
