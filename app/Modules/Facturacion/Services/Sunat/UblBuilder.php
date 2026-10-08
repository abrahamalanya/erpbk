<?php

namespace App\Modules\Facturacion\Services\Sunat;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ComprobanteDetalle;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use Greenter\Xml\Builder\InvoiceBuilder;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Client\Client;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Tax;
use Greenter\Model\Sale\TaxTotal;
use Greenter\Model\Sale\PaymentTerms;
use Greenter\Model\Sale\PaymentMeans;
use Greenter\Model\Sale\Perception;
use Greenter\Model\Sale\PerceptionDetail;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Report\Report;
use Illuminate\Support\Facades\Log;
use DomainException;
use NumberFormatter;
use Carbon\Carbon;

/**
 * Constructor del XML UBL 2.1 usando Greenter/xml.
 *
 * Greenter maneja toda la estructura UBL, namespaces, firmas, catálogos SUNAT.
 * Este servicio adapta nuestros modelos (Comprobante + ComprobanteDetalle)
 * a los modelos de Greenter.
 */
final class UblBuilder
{
    public function __construct(
        private readonly InvoiceBuilder $invoiceBuilder,
    ) {}

    /**
     * Genera el XML firmado y listo para enviar a SUNAT.
     *
     * @return string XML firmado
     */
    public function build(Comprobante $comprobante): string
    {
        $invoice = $this->mapearComprobante($comprobante);

        return $this->invoiceBuilder->getXmlSigned($invoice);
    }

    /**
     * Genera el XML SIN firmar (para validación contra XSD, preview, debug).
     */
    public function buildSinFirmar(Comprobante $comprobante): string
    {
        $invoice = $this->mapearComprobante($comprobante);

        return $this->invoiceBuilder->getXml($invoice);
    }

    /**
     * Genera el ZIP con el XML firmado (lo que SUNAT espera recibir).
     */
    public function buildZip(Comprobante $comprobante): string
    {
        $xml = $this->build($comprobante);
        $filename = $this->nombreArchivo($comprobante);

        return $this->invoiceBuilder->getZip($xml, $filename);
    }

    /**
     * Mapea nuestro Comprobante + Detalles al modelo Invoice de Greenter.
     */
    private function mapearComprobante(Comprobante $comprobante): Invoice
    {
        $invoice = new Invoice();

        // --- Cabecera ---
        $invoice->setTipoOperacion('0101') // Venta interna
            ->setTipoDoc($comprobante->tipo_documento) // 01, 03, 07
            ->setSerie($comprobante->serie)
            ->setCorrelativo($comprobante->correlativo)
            ->setFechaEmision($comprobante->fecha_emision->format('Y-m-d'))
            ->setHoraEmision($comprobante->hora_emision->format('H:i:s'))
            ->setMoneda($comprobante->moneda)
            ->setFormaPago($comprobante->forma_pago) // 01 contado, 03 crédito, P0xx detracción
            ->setTipoDocIdentidad($this->tipoDocReceptor($comprobante))
            ->setNumDocIdentidad($comprobante->receptor_numero_documento)
            ->setRazonSocial($comprobante->receptor_nombre);

        // Dirección del receptor (obligatoria para factura)
        if ($comprobante->receptor_domicilio || $comprobante->receptor_ubigeo) {
            $address = new Address();
            $address->setDireccion($comprobante->receptor_domicilio ?? '-')
                ->setUrbanizacion('-')
                ->setDepartamento($this->ubigeoADepartamento($comprobante->receptor_ubigeo))
                ->setProvincia($this->ubigeoAProvincia($comprobante->receptor_ubigeo))
                ->setDistrito($this->ubigeoADistrito($comprobante->receptor_ubigeo))
                ->setCodLocal($comprobante->receptor_ubigeo ?? '000000');
            $invoice->setDireccion($address);
        }

        // Email del receptor
        if ($comprobante->receptor_email) {
            $invoice->setEmail($comprobante->receptor_email);
        }

        // --- Emisor (Company) ---
        $company = new Company();
        $company->setRuc($comprobante->emisor_ruc)
            ->setRazonSocial($comprobante->emisor_razon_social)
            ->setNombreComercial($comprobante->emisor_nombre_comercial ?? $comprobante->emisor_razon_social);

        $addressEmisor = new Address();
        $addressEmisor->setDireccion($comprobante->emisor_domicilio ?? '-')
            ->setUrbanizacion('-')
            ->setDepartamento($this->ubigeoADepartamento($comprobante->emisor_ubigeo))
            ->setProvincia($this->ubigeoAProvincia($comprobante->emisor_ubigeo))
            ->setDistrito($this->ubigeoADistrito($comprobante->emisor_ubigeo))
            ->setCodLocal($comprobante->emisor_ubigeo ?? '000000');
        $company->setAddress($addressEmisor);

        $invoice->setCompany($company);

        // --- Detalles ---
        /** @var ComprobanteDetalle $detalle */
        foreach ($comprobante->detalles as $detalle) {
            $saleDetail = new SaleDetail();
            $saleDetail->setCodProducto($detalle->codigo_producto ?? $detalle->orden)
                ->setUnidadMedida($detalle->unidad_medida) // ZZ, NIU, etc.
                ->setCantidad((float) $detalle->cantidad)
                ->setDescripcion($detalle->descripcion)
                ->setValorUnitario((float) $detalle->valor_unitario)
                ->setPrecioUnitario((float) $detalle->precio_unitario)
                ->setDescuento((float) $detalle->descuento)
                ->setValorVenta((float) $detalle->valor_venta)
                ->setCodProductoSunat('00000000'); // Código genérico para servicios

            // Impuestos por línea
            $tax = new Tax();
            $tax->setId($detalle->categoria_tributo->value) // S, G, E, B
                ->setNombre('IGV')
                ->setTipoCodigo('10') // IGV
                ->setPorcentaje((float) $detalle->porcentaje_igv)
                ->setMonto((float) $detalle->igv)
                ->setBaseImponible((float) $detalle->valor_venta);

            $taxTotal = new TaxTotal();
            $taxTotal->setTax($tax);
            $saleDetail->setTaxTotal($taxTotal);

            // Categoría de tributo (afectación IGV)
            $saleDetail->setAfectacionIgv($detalle->categoria_tributo->value);

            $invoice->addDetail($saleDetail);
        }

        // --- Leyenda (importe en letras) ---
        $legend = new Legend();
        $legend->setCode('1000')
            ->setValue($comprobante->importe_letras);
        $invoice->setLegend($legend);

        // --- Totales (MonetaryTotal) ---
        $invoice->setTotalVenta((float) $comprobante->subtotal)
            ->setTotalDescuento((float) $comprobante->descuento_global)
            ->setTotalIgv((float) $comprobante->igv)
            ->setTotalImpuestos((float) $comprobante->igv)
            ->setTotal((float) $comprobante->total);

        // TaxTotal a nivel documento
        $taxTotalDoc = new TaxTotal();
        $taxDoc = new Tax();
        $taxDoc->setId('S')
            ->setNombre('IGV')
            ->setTipoCodigo('10')
            ->setPorcentaje((float) $comprobante->porcentaje_igv)
            ->setMonto((float) $comprobante->igv)
            ->setBaseImponible((float) $comprobante->subtotal);
        $taxTotalDoc->setTax($taxDoc);
        $invoice->setTaxTotal($taxTotalDoc);

        // --- Forma de pago (PaymentTerms) ---
        $paymentTerms = new PaymentTerms();
        $paymentTerms->setId($comprobante->forma_pago);
        $invoice->setPaymentTerms($paymentTerms);

        // --- Medio de pago (PaymentMeans) ---
        if ($comprobante->medio_pago) {
            $paymentMeans = new PaymentMeans();
            $paymentMeans->setId($comprobante->medio_pago); // 005, 003, etc.
            $invoice->setPaymentMeans($paymentMeans);
        }

        // --- Detracción (si aplica) ---
        if ($comprobante->codigo_detraccion) {
            $paymentTerms->setDetraccion(true)
                ->setDetraccionCodigo($comprobante->codigo_detraccion);
        }

        // --- Observaciones ---
        if ($comprobante->observaciones) {
            $invoice->setObservaciones($comprobante->observaciones);
        }

        return $invoice;
    }

    private function tipoDocReceptor(Comprobante $comprobante): string
    {
        return match ($comprobante->receptor_tipo_documento) {
            'dni' => '1',
            'ruc' => '6',
            'ce' => '4',
            'pasaporte' => '7',
            default => '0',
        };
    }

    private function ubigeoADepartamento(?string $ubigeo): string
    {
        if (! $ubigeo || strlen($ubigeo) < 2) {
            return 'LIMA';
        }
        $dep = substr($ubigeo, 0, 2);
        return match ($dep) {
            '01' => 'AMAZONAS', '02' => 'ANCASH', '03' => 'APURIMAC', '04' => 'AREQUIPA',
            '05' => 'AYACUCHO', '06' => 'CAJAMARCA', '07' => 'CALLAO', '08' => 'CUSCO',
            '09' => 'HUANCAVELICA', '10' => 'HUANUCO', '11' => 'ICA', '12' => 'JUNIN',
            '13' => 'LA LIBERTAD', '14' => 'LAMBAYEQUE', '15' => 'LIMA', '16' => 'LORETO',
            '17' => 'MADRE DE DIOS', '18' => 'MOQUEGUA', '19' => 'PASCO', '20' => 'PIURA',
            '21' => 'PUNO', '22' => 'SAN MARTIN', '23' => 'TACNA', '24' => 'TUMBES',
            '25' => 'UCAYALI', default => 'LIMA',
        };
    }

    private function ubigeoAProvincia(?string $ubigeo): string
    {
        if (! $ubigeo || strlen($ubigeo) < 4) {
            return 'LIMA';
        }
        // Simplificado: en producción usar catálogo ubigeo real
        return 'LIMA';
    }

    private function ubigeoADistrito(?string $ubigeo): string
    {
        if (! $ubigeo || strlen($ubigeo) < 6) {
            return 'LIMA';
        }
        return 'LIMA';
    }

    private function nombreArchivo(Comprobante $comprobante): string
    {
        return sprintf(
            '%s-%s-%s-%s.xml',
            $comprobante->emisor_ruc,
            $comprobante->tipo_documento,
            $comprobante->serie,
            str_pad((string) $comprobante->correlativo, 8, '0', STR_PAD_LEFT)
        );
    }
}