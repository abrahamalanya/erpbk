<?php

namespace App\Modules\Venta\Policies;

use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\Venta;

class VentaPolicy
{
    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('ventas.ver');
    }

    public function view(User $user, Venta $venta): bool
    {
        return $user->can('ventas.ver') && $this->puedeOperar($user, $venta);
    }

    public function create(User $user): bool
    {
        return $user->can('ventas.crear');
    }

    public function cobrar(User $user, Venta $venta): bool
    {
        return $user->can('ventas.cobrar') && $this->puedeOperar($user, $venta);
    }

    /**
     * Cancelar un apartado hace que el cliente pierda el inicial y los
     * abonos entregados — misma autoridad admin que
     * ConfiguracionVentaPolicy y que las acciones sensibles de Credito
     * (enviarATienda/vender): un asesor puede registrar y cobrar sus
     * propias ventas, pero no decide esto.
     */
    public function cancelar(User $user, Venta $venta): bool
    {
        return $user->can('ventas.cancelar') && $this->puedeAdministrar($user, $venta);
    }

    /**
     * view/cobrar: un asesor solo opera las ventas que él mismo registró
     * (vendido_por); un administrador, las de su agencia/empresa — mismo
     * criterio que CreditoHierarchyService::puedeVer() para asesor.
     */
    private function puedeOperar(User $user, Venta $venta): bool
    {
        if ($user->hasRole('asesor')) {
            return $venta->vendido_por === $user->id;
        }

        return $this->puedeAdministrar($user, $venta);
    }

    private function puedeAdministrar(User $user, Venta $venta): bool
    {
        if ($user->hasRole('administrador_general')) {
            return $user->empresa_id === $venta->empresa_id;
        }

        if ($user->hasRole('administrador_agencia')) {
            return $user->agencia_id === $venta->agencia_id;
        }

        return false;
    }
}
