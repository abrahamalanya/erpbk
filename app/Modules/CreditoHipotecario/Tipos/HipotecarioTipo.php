<?php

namespace App\Modules\CreditoHipotecario\Tipos;

use App\Modules\Credito\Tipos\CreditoTipoSupervisado;
use App\Modules\CreditoHipotecario\Models\Inmueble;

/**
 * Crédito hipotecario: garantía es un Inmueble (partida registral SUNARP).
 * Comparte con vehicular los requisitos de garantía formal
 * (dirección/referencia del cliente, supervisado por, conformidad del
 * notario/abogado previa a la tienda) vía CreditoTipoSupervisado.
 */
final class HipotecarioTipo extends CreditoTipoSupervisado
{
    public function clave(): string
    {
        return 'hipotecario';
    }

    public function garantiaModelo(): string
    {
        return Inmueble::class;
    }

    protected function etiqueta(): string
    {
        return 'hipotecario';
    }

    protected function moduloVistas(): string
    {
        return 'credito-hipotecario';
    }
}
