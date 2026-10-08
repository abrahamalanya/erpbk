<?php

namespace App\Modules\Facturacion\Policies;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;

/**
 * Configuración de facturación: quién la ve y quién la edita.
 *
 * La edita solo `administrador_general` (y `sistemas` vía before()). Es la
 * pantalla donde se define el IGV, la moneda y el modo beta/producción, así
 * que no se delega a roles operativos.
 */
class ConfiguracionFacturacionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('configuraciones_facturacion.ver');
    }

    public function view(User $user): bool
    {
        return $user->can('configuraciones_facturacion.ver');
    }

    public function update(User $user, Empresa $empresa): bool
    {
        if (! $user->can('configuraciones_facturacion.editar')) {
            return false;
        }

        return $user->empresa_id === $empresa->id;
    }
}
