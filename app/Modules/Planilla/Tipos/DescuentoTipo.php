<?php

namespace App\Modules\Planilla\Tipos;

/**
 * Los tipos de descuento que se pueden lanzar contra el sueldo de un
 * trabajador. Los defaults son los que pediste en APUNTES.md; el enum existe
 * para que el frontend tenga un catálogo cerrado y el backend pueda validar
 * sin una tabla aparte.
 */
enum DescuentoTipo: string
{
    case Adelanto = 'adelanto';
    case Prestamo = 'prestamo';
    case Falta = 'falta';
    case RiesgoCaja = 'riesgo_caja';
    case Otro = 'otro';

    /**
     * Un adelanto es un pago anticipado de sueldo: se le devuelve al trabajador
     * en un solo descuento grande (el mes que acuerdan). Un
     * préstamo o una falta se descuentan en cuotas que se configuran al
     * registrar el descuento.
     */
    public function esCuoteado(): bool
    {
        return $this !== self::Adelanto;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Adelanto => 'Adelanto de sueldo',
            self::Prestamo => 'Préstamo al trabajador',
            self::Falta => 'Falta',
            self::RiesgoCaja => 'Riesgo de caja',
            self::Otro => 'Otro descuento',
        };
    }
}
