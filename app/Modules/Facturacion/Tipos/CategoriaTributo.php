<?php

namespace App\Modules\Facturacion\Tipos;

/**
 * Categoría de tributo del catálogo 08 de SUNAT, que va en
 * `cac:TaxSubtotal/cbc:TaxCategoryCode` de cada línea del XML.
 */
enum CategoriaTributo: string
{
    /** Gravado — es el 100% de lo que factura este sistema. */
    case Gravado = 'S';

    /** Exonerado. */
    case Exonerado = 'G';

    /** Gratuito. */
    case Gratuito = 'E';

    /**
     * Bonificaciones: solo informa, no suma al total.
     */
    case Bonificacion = 'B';

    public function aplicaIgv(): bool
    {
        return $this === self::Gravado;
    }
}
