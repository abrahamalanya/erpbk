<?php

namespace App\Modules\Caja\Policies;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Usuario\Models\User;

class CuentaBancariaPolicy
{
    public function __construct(private readonly CajaBovedaHierarchyService $hierarchy) {}

    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('cuentas_bancarias.ver');
    }

    public function view(User $user, CuentaBancaria $cuentaBancaria): bool
    {
        if (! $user->can('cuentas_bancarias.ver')) {
            return false;
        }

        if ($user->hasRole('administrador_agencia')) {
            return $cuentaBancaria->boveda->agencia_id === $user->agencia_id;
        }

        if ($user->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $cuentaBancaria->empresa_id === $user->empresa_id;
        }

        return false;
    }

    /**
     * Managing a bóveda's cuentas bancarias — creating, editing, deleting,
     * registering movimientos, conciliando — is restricted to whoever
     * controls that bóveda, same authority as BovedaPolicy::cerrar()/reabrir().
     * Authorized via Gate::authorize('crear', [CuentaBancaria::class, $boveda])
     * since there's no CuentaBancaria instance yet at creation time.
     */
    public function crear(User $user, Boveda $boveda): bool
    {
        return $user->can('cuentas_bancarias.crear') && $this->hierarchy->puedeControlarBoveda($user, $boveda);
    }

    public function editar(User $user, CuentaBancaria $cuentaBancaria): bool
    {
        return $user->can('cuentas_bancarias.editar') && $this->hierarchy->puedeControlarBoveda($user, $cuentaBancaria->boveda);
    }

    public function eliminar(User $user, CuentaBancaria $cuentaBancaria): bool
    {
        return $user->can('cuentas_bancarias.eliminar') && $this->hierarchy->puedeControlarBoveda($user, $cuentaBancaria->boveda);
    }

    public function movimiento(User $user, CuentaBancaria $cuentaBancaria): bool
    {
        return $user->can('cuentas_bancarias.movimiento') && $this->hierarchy->puedeControlarBoveda($user, $cuentaBancaria->boveda);
    }

    public function conciliar(User $user, CuentaBancaria $cuentaBancaria): bool
    {
        return $user->can('cuentas_bancarias.conciliar') && $this->hierarchy->puedeControlarBoveda($user, $cuentaBancaria->boveda);
    }
}
