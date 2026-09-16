<?php

namespace App\Modules\Cobranza\Policies;

use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Usuario\Models\User;

class CobroPolicy
{
    /**
     * 'sistemas' pasa por encima de todo, igual que en las demás policies.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('cobranzas.ver');
    }

    /**
     * Ver los créditos pendientes de un cliente para elegir cuál cobrar. El
     * cobro en sí lo autorizan las policies de refrendar()/liquidar().
     */
    public function registrar(User $user): bool
    {
        return $user->can('cobranzas.registrar');
    }

    /**
     * Misma autoridad que registrar() — el candado real de "solo mientras tu
     * caja sigue abierta" (con excepción de administrador_general, que puede
     * anular sin importar la fecha) lo aplica CreditoService::anularCobro(),
     * así que esto solo verifica el permiso general.
     */
    public function anular(User $user, Cobro $cobro): bool
    {
        return $user->can('cobranzas.registrar');
    }
}
