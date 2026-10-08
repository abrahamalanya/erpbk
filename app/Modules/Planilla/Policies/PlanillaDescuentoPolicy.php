<?php

namespace App\Modules\Planilla\Policies;

use App\Modules\Planilla\Models\PlanillaDescuento;
use App\Modules\Usuario\Models\User;

/**
 * Adelantos, préstamos y descuentos contra el sueldo. Policy aparte de
 * PlanillaPolicy porque el Gate resuelve por modelo, y el alcance es el mismo
 * (solo los roles que administran personal, y dentro de su empresa o agencia).
 */
class PlanillaDescuentoPolicy
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
        return $user->can('planillas.descuentos') && $this->esDeConfianza($user);
    }

    public function view(User $user, PlanillaDescuento $descuento): bool
    {
        if (! $user->can('planillas.descuentos')) {
            return false;
        }

        if ($user->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $user->empresa_id === $descuento->empresa_id;
        }

        return $user->hasRole('administrador_agencia') && $user->agencia_id === $descuento->agencia_id;
    }

    public function create(User $user): bool
    {
        return $user->can('planillas.descuentos') && $this->esDeConfianza($user);
    }

    /**
     * Anular un descuento ya saldado no tiene sentido: ese dinero ya se cobró
     * del sueldo. El controller lo rechaza con un 422 en vez de dejar que la
     * base lo admita.
     */
    public function anular(User $user, PlanillaDescuento $descuento): bool
    {
        return $this->view($user, $descuento);
    }

    private function esDeConfianza(User $user): bool
    {
        return $user->hasAnyRole(['administrador_general', 'administrador_agencia', 'secretaria']);
    }
}
