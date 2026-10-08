<?php

namespace App\Modules\Facturacion\Policies;

use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Usuario\Models\User;

class SerieComprobantePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('configuraciones_facturacion.ver');
    }

    public function create(User $user): bool
    {
        return $user->can('configuraciones_facturacion.editar');
    }

    public function update(User $user, SerieComprobante $serie): bool
    {
        return $user->can('configuraciones_facturacion.editar') && $user->empresa_id === $serie->empresa_id;
    }

    public function delete(User $user, SerieComprobante $serie): bool
    {
        return $user->can('configuraciones_facturacion.editar') && $user->empresa_id === $serie->empresa_id;
    }
}
