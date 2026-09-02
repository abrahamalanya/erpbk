<?php

namespace App\Modules\CreditoVehicular\Tipos;

use App\Modules\Credito\Tipos\CreditoTipoSupervisado;
use App\Modules\CreditoVehicular\Models\Vehiculo;

/**
 * Crédito vehicular: garantía es un Vehiculo. Comparte con hipotecario los
 * requisitos de garantía formal (dirección/referencia del cliente,
 * supervisado por, conformidad previa a la tienda) vía CreditoTipoSupervisado.
 */
final class VehicularTipo extends CreditoTipoSupervisado
{
    public function clave(): string
    {
        return 'vehicular';
    }

    public function garantiaModelo(): string
    {
        return Vehiculo::class;
    }

    protected function etiqueta(): string
    {
        return 'vehicular';
    }

    protected function moduloVistas(): string
    {
        return 'credito-vehicular';
    }
}
