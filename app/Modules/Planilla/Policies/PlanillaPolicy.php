<?php

namespace App\Modules\Planilla\Policies;

use App\Modules\Planilla\Models\Planilla;
use App\Modules\Usuario\Models\User;

/**
 * Planilla: la ven (y la registran) solo los roles de confianza — los mismos
 * que ya manejan los permisos de toda la empresa. Ni asesor ni supervisor
 * entran: los sueldos del personal no se muestran al personal.
 *
 * Los descuentos (adelantos, préstamos, faltas) tienen su propia policy
 * — PlanillaDescuentoPolicy — porque el Gate resuelve por modelo.
 */
class PlanillaPolicy
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
        return $user->can('planillas.ver') && $this->esDeConfianza($user);
    }

    public function view(User $user, Planilla $planilla): bool
    {
        if (! $user->can('planillas.ver')) {
            return false;
        }

        if ($user->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $user->empresa_id === $planilla->empresa_id;
        }

        return $user->hasRole('administrador_agencia') && $user->agencia_id === $planilla->agencia_id;
    }

    public function create(User $user): bool
    {
        return $user->can('planillas.crear') && $this->esDeConfianza($user);
    }

    public function update(User $user, Planilla $planilla): bool
    {
        return $user->can('planillas.editar') && $this->view($user, $planilla);
    }

    /**
     * Las planillas no se borran: se anulan o se corrigen con una planilla
     * nueva. Un delete deja el reporte de un mes pagado sin sustento.
     */
    public function delete(User $user, Planilla $planilla): bool
    {
        return false;
    }

    /**
     * Mismo criterio de rol que la vista: los que administran personal.
     */
    private function esDeConfianza(User $user): bool
    {
        return $user->hasAnyRole(['administrador_general', 'administrador_agencia', 'secretaria']);
    }
}
