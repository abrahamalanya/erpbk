<?php

namespace App\Modules\Facturacion\Policies;

use App\Modules\Credito\Models\Credito;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Usuario\Models\User;

/**
 * Autorización del módulo de facturación.
 *
 * La regla de oro: `anular` y `emitir_nota_credito` son de
 * `administrador_general` únicamente. Quien puede ver el comprobante de todos
 * no debería poder borrar su evidencia fiscal.
 *
 * La emisión (`crear`) la hacen los roles que ya operan créditos y cajas,
 * porque son quienes eligen a quién facturar en la pantalla de créditos
 * pendientes.
 */
class ComprobantePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('sistemas') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('facturaciones.ver');
    }

    public function view(User $user, Comprobante $comprobante): bool
    {
        if (! $user->can('facturaciones.ver')) {
            return false;
        }

        // Un asesor solo ve los comprobantes de sus propios créditos.
        if ($user->hasRole('asesor')) {
            return $comprobante->origen_tipo === 'credito'
                && Credito::query()->whereKey($comprobante->origen_id)->where('registrado_por', $user->id)->exists();
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('facturaciones.crear');
    }

    public function anular(User $user): bool
    {
        return $user->hasRole('administrador_general');
    }

    public function emitirNotaCredito(User $user): bool
    {
        return $user->hasRole('administrador_general');
    }

    public function descargar(User $user): bool
    {
        return $user->can('facturaciones.descargar');
    }
}
