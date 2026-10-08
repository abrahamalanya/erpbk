<?php

namespace App\Modules\Facturacion\Services\Sunat;

use App\Modules\Facturacion\Tipos\CategoriaTributo;
use DomainException;

/**
 * Catálogos de SUNAT que el módulo necesita. Solo están los valores que el
 * sistema usa de verdad — mantener el Anexo 8 completo en el código sería ruido
 * que nadie lee y nadie actualiza. Si aparece un valor nuevo, se agrega aquí.
 *
 * Fuente: Anexo N.° 8 "Catálogos de códigos de sistemas" de la Resolución
 * N.° 300-2015/SUNAT y sus modificatorias. Los códigos marcados como
 * "verificar" en FACTURACION.md §12 están pendientes de contraste contra la
 * versión vigente antes del go-live.
 */
final class CatalogoSunat
{
    /**
     * Tipo de documento del receptor (catálogo 06), que va en
     * `cac:AdditionalDocumentReference/cbc:ID`.
     *
     * @var array<string, string>
     */
    public const TIPO_DOCUMENTO_RECEPTOR = [
        'dni' => '0',
        'ruc' => '6',
        'ce' => '4',
        'pasaporte' => '7',
        'carnet_diplomatico' => 'A',
    ];

    /**
     * Tipo de documento de identidad válido para cada tipo de receptor. Un
     * cliente tiene que poder identificarse con su RUC para que la factura
     * salga, porque toda factura electrónica exige RUC de receptor.
     *
     * @var array<string, int>
     */
    public const LONGITUD_DOCUMENTO = [
        'dni' => 8,
        'ruc' => 11,
        'ce' => 9,
        'pasaporte' => 9,
    ];

    /**
     * Tipo de comprobante (catálogo 01) — vive en el enum TipoComprobante.
     * Este mapa es solo para validar entradas genéricas.
     *
     * @var array<string, string>
     */
    public const TIPO_COMPROBANTE = [
        'factura' => '01',
        'boleta' => '03',
        'nota_credito' => '07',
    ];

    /**
     * Categoría de tributo (catálogo 08) — vive en el enum CategoriaTributo.
     *
     * @var array<string, string>
     */
    public const CATEGORIA_TRIBUTO = [
        'gravado' => 'S',
        'exonerado' => 'G',
        'gratuito' => 'E',
        'bonificacion' => 'B',
    ];

    /**
     * Forma de pago (catálogo 54), que va en `cac:PaymentTerms/cbc:ID`.
     *
     * @var array<string, string>
     */
    public const FORMA_PAGO = [
        'contado' => '01',
        'credito' => '03',
        'detraccion' => 'P01',
    ];

    /**
     * Medio de pago (catálogo 05). El sistema ya usa nombres propios
     * (`efectivo`, `yape`, `plin`, `transferencia`) en `caja_movimientos.medio`
     * y `cobros.medio`; este mapa traduce esos nombres a los códigos de SUNAT.
     *
     * @var array<string, string>
     */
    public const MEDIO_PAGO = [
        'efectivo' => '005',
        'transferencia' => '003',
        'yape' => '003',
        'plin' => '003',
        'tarjeta' => '008',
    ];

    /**
     * Unidad de medida (catálogo 03/UNEI). Para servicios de crédito la
     * unidad aplicable es "adimensional" (ZZ) o "unidad" (NIU).
     *
     * @var array<string, string>
     */
    public const UNIDAD_MEDIDA = [
        'adimensional' => 'ZZ',
        'unidad' => 'NIU',
    ];

    public const MONEDA = 'PEN';

    /**
     * IGV vigente en Perú.
     */
    public const IGV_PORCENTAJE = '18.00';

    /**
     * Traduce el medio de pago interno del sistema al código de SUNAT. Los
     * medios que no están mapeados caen en efectivo, que es el caso más común
     * en una caja y nunca debe romper la emisión.
     */
    public static function medioPago(string $medio): string
    {
        return self::MEDIO_PAGO[strtolower($medio)] ?? self::MEDIO_PAGO['efectivo'];
    }

    public static function categoriaTributoGravado(): string
    {
        return CategoriaTributo::Gravado->value;
    }

    /**
     * Tipo de documento del receptor a partir del tipo que guarda el cliente
     * en `clientes.tipo_documento`.
     *
     * @throws DomainException si el tipo no es un documento válido de SUNAT.
     */
    public static function tipoDocumentoReceptor(string $tipoDocumento): string
    {
        $codigo = self::TIPO_DOCUMENTO_RECEPTOR[strtolower($tipoDocumento)] ?? null;

        if ($codigo === null) {
            throw new DomainException(
                "El tipo de documento '{$tipoDocumento}' no existe en el catálogo de SUNAT."
            );
        }

        return $codigo;
    }
}
