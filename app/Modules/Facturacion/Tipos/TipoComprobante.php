<?php

namespace App\Modules\Facturacion\Tipos;

/**
 * Tipos de comprobante de pago electrónico del catálogo 01 de SUNAT. El código
 * de dos dígitos es el que va en `cbc:InvoiceTypeCode` del XML y en el nombre
 * del archivo que se envía al Web Service.
 */
enum TipoComprobante: string
{
    case Factura = '01';

    case Boleta = '03';

    case NotaCredito = '07';

    /**
     * Denominación que SUNAT exige imprimir en el comprobante.
     */
    public function denominacion(): string
    {
        return match ($this) {
            self::Factura => 'Factura Electrónica',
            self::Boleta => 'Boleta de Venta Electrónica',
            self::NotaCredito => 'Nota de Crédito Electrónica',
        };
    }

    /**
     * Serie con la que arranca cada tipo: las series de factura y las de
     * boleta las autoriza SUNAT por separado y no son intercambiables.
     */
    public function serieInicial(): string
    {
        return match ($this) {
            self::Factura => 'F001',
            self::Boleta => 'B001',
            self::NotaCredito => 'BC01',
        };
    }

    /**
     * La nota de crédito hereda la serie del documento que corrige, así que
     * no tiene serie propia por defecto.
     */
    public function requiereSeriePropia(): bool
    {
        return $this !== self::NotaCredito;
    }

    /**
     * Los comprobantes que admiten comunicación de baja ante SUNAT (en vez de
     * solo nota de crédito) son los de venta. La nota de crédito no se anula:
     * se corrige emitiendo otra contra ella.
     *
     * @return list<self>
     */
    public static function anulables(): array
    {
        return [self::Factura, self::Boleta];
    }
}
