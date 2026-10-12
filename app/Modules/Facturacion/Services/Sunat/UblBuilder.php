<?php

namespace App\Modules\Facturacion\Services\Sunat;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ComprobanteDetalle;
use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Facturacion\Tipos\CategoriaTributo;
use DomainException;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\PaymentTerms;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Xml\Builder\InvoiceBuilder;
use Greenter\Zip\ZipFly;
use Illuminate\Support\Facades\Storage;

/**
 * Constructor del XML UBL 2.1 usando Greenter/xml, firmado con FirmanteDigital.
 *
 * Greenter maneja toda la estructura UBL, namespaces y catálogos SUNAT —
 * `InvoiceBuilder::build()` ya devuelve un XML con el hueco
 * `<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>`
 * listo para que FirmanteDigital inserte la firma ahí. Este servicio adapta
 * nuestros modelos (Comprobante + ComprobanteDetalle) a los modelos de
 * Greenter y arma el ZIP de archivo (lo que `comprobantes.zip_path` guarda;
 * el envío SOAP a SUNAT comprime por su cuenta, ver SunatSoapClient).
 *
 * `mapearComprobante()` se verificó método por método contra las clases
 * REALMENTE instaladas (no contra su documentación/ejemplos): la primera
 * versión llamaba a más de 20 métodos que no existen en `greenter/core` ni
 * `greenter/xml` (`Invoice::setMoneda`, `SaleDetail::setValorUnitario`,
 * clases enteras como `Greenter\Model\Sale\Tax`/`TaxTotal`/`PaymentMeans`)
 * — nunca se había ejecutado ni un test la tocaba. Los nombres reales:
 * - El receptor es un `Client` aparte (`$invoice->setClient()`), no campos
 *   sueltos en `Invoice`.
 * - `SaleDetail` tiene los campos de IGV planos (`setMtoBaseIgv`,
 *   `setPorcentajeIgv`, `setIgv`, `setTipAfeIgv` con el catálogo 07 de
 *   SUNAT) — no hay un objeto `Tax`/`TaxTotal` anidado.
 * - Los totales del documento son el desglose por tipo de operación
 *   (`setMtoOperGravadas`, `setMtoIGV`, `setMtoImpVenta`...), no un
 *   `subtotal`/`total` directo.
 * - No hay forma de modelar el medio de pago (efectivo/yape/...) en esta
 *   versión de greenter/xml — se omite.
 */
final class UblBuilder
{
    public function __construct(
        private readonly InvoiceBuilder $invoiceBuilder,
        private readonly FirmanteDigital $firmante,
    ) {}

    /**
     * Genera el XML firmado y listo para enviar a SUNAT.
     *
     * @return string XML firmado
     */
    public function build(Comprobante $comprobante): string
    {
        $xmlSinFirmar = $this->buildSinFirmar($comprobante);
        $credencial = $this->credencialDe($comprobante);

        return $this->firmante->firmar(
            $xmlSinFirmar,
            Storage::disk('privado')->get($credencial->certificado_path),
            $credencial->certificado_password,
        );
    }

    /**
     * Genera el XML SIN firmar (para validación contra XSD, preview, debug).
     */
    public function buildSinFirmar(Comprobante $comprobante): string
    {
        $invoice = $this->mapearComprobante($comprobante);

        return $this->invoiceBuilder->build($invoice);
    }

    /**
     * Genera el ZIP con el XML firmado — lo que se archiva en
     * `comprobantes.zip_path`. El envío a SUNAT comprime por su cuenta
     * (BillSender::send()), así que este ZIP es solo para el archivo.
     */
    public function buildZip(Comprobante $comprobante): string
    {
        $xml = $this->build($comprobante);
        $filename = $this->nombreArchivo($comprobante);

        return (new ZipFly)->compress($filename, $xml);
    }

    /**
     * Credencial SUNAT de la empresa del comprobante, con el certificado
     * listo para usar. Lanza un mensaje accionable si falta algo, en vez de
     * dejar que Storage/FirmanteDigital fallen con un error críptico.
     */
    private function credencialDe(Comprobante $comprobante): CredencialSunat
    {
        $credencial = $comprobante->empresa->credencialSunat;

        if (! $credencial || ! $credencial->estaCompleta()) {
            throw new DomainException('Credenciales SUNAT incompletas: falta usuario SOL, clave SOL o certificado .pfx.');
        }

        if ($credencial->estaVencido()) {
            throw new DomainException('El certificado digital está vencido. Sube uno vigente antes de emitir.');
        }

        if (! Storage::disk('privado')->exists($credencial->certificado_path)) {
            throw new DomainException('El archivo del certificado digital no se encuentra en el servidor.');
        }

        return $credencial;
    }

    /**
     * Mapea nuestro Comprobante + Detalles al modelo Invoice de Greenter.
     */
    private function mapearComprobante(Comprobante $comprobante): Invoice
    {
        $invoice = new Invoice;

        // --- Cabecera ---
        // La UBL 2.0 es la que greenter/xml usa por defecto; SUNAT pide 2.1
        // (FACTURACION.md §5.6), que es la que trae el hueco de firma que
        // FirmanteDigital necesita.
        $invoice->setUblVersion('2.1')
            ->setTipoOperacion('0101') // Venta interna
            ->setTipoDoc($comprobante->tipo_documento->value) // 01, 03, 07
            ->setSerie($comprobante->serie)
            // La plantilla arma el <cbc:ID> como "{serie}-{correlativo}" sin
            // rellenar: el correlativo va con los 8 dígitos ya puestos.
            ->setCorrelativo(str_pad((string) $comprobante->correlativo, 8, '0', STR_PAD_LEFT))
            // La plantilla UBL saca IssueDate e IssueTime del mismo valor
            // (invoice2.1.xml.twig), así que va la fecha y la hora juntas.
            ->setFechaEmision($comprobante->fecha_emision->copy()->setTimeFromTimeString((string) $comprobante->hora_emision))
            ->setTipoMoneda($comprobante->moneda);

        if ($comprobante->observaciones) {
            $invoice->setObservacion($comprobante->observaciones);
        }

        // --- Receptor (Client) ---
        $client = new Client;
        $client->setTipoDoc($this->tipoDocReceptor($comprobante))
            ->setNumDoc($comprobante->receptor_numero_documento)
            ->setRznSocial($comprobante->receptor_nombre);

        if ($comprobante->receptor_email) {
            $client->setEmail($comprobante->receptor_email);
        }

        if ($comprobante->receptor_domicilio || $comprobante->receptor_ubigeo) {
            $address = new Address;
            $address->setDireccion($comprobante->receptor_domicilio ?? '-')
                ->setUrbanizacion('-')
                ->setUbigueo($comprobante->receptor_ubigeo ?? '000000')
                ->setDepartamento($this->ubigeoADepartamento($comprobante->receptor_ubigeo))
                ->setProvincia($this->ubigeoAProvincia($comprobante->receptor_ubigeo))
                ->setDistrito($this->ubigeoADistrito($comprobante->receptor_ubigeo));
            $client->setAddress($address);
        }

        $invoice->setClient($client);

        // --- Emisor (Company) ---
        $company = new Company;
        $company->setRuc($comprobante->emisor_ruc)
            ->setRazonSocial($comprobante->emisor_razon_social)
            ->setNombreComercial($comprobante->emisor_nombre_comercial ?? $comprobante->emisor_razon_social);

        $addressEmisor = new Address;
        $addressEmisor->setDireccion($comprobante->emisor_domicilio ?? '-')
            ->setUrbanizacion('-')
            ->setUbigueo($comprobante->emisor_ubigeo ?? '000000')
            ->setDepartamento($this->ubigeoADepartamento($comprobante->emisor_ubigeo))
            ->setProvincia($this->ubigeoAProvincia($comprobante->emisor_ubigeo))
            ->setDistrito($this->ubigeoADistrito($comprobante->emisor_ubigeo));
        $company->setAddress($addressEmisor);

        $invoice->setCompany($company);

        // --- Detalles: SaleDetail trae los campos del IGV planos (no hay un
        // objeto Tax/TaxTotal anidado en esta versión de greenter/xml). ---
        $detalles = [];

        /** @var ComprobanteDetalle $detalle */
        foreach ($comprobante->detalles as $detalle) {
            $saleDetail = new SaleDetail;
            $saleDetail->setCodProducto($detalle->codigo_producto ?? (string) $detalle->orden)
                ->setCodProdSunat('99999999') // Catálogo 25: código genérico para servicios no detallados.
                ->setUnidad($detalle->unidad_medida) // ZZ, NIU, etc.
                ->setCantidad((float) $detalle->cantidad)
                ->setDescripcion($detalle->descripcion)
                ->setMtoValorUnitario((float) $detalle->valor_unitario)
                ->setMtoPrecioUnitario((float) $detalle->precio_unitario)
                ->setDescuento((float) $detalle->descuento)
                ->setMtoValorVenta((float) $detalle->valor_venta)
                ->setMtoBaseIgv((float) $detalle->valor_venta)
                ->setPorcentajeIgv((float) $detalle->porcentaje_igv)
                ->setIgv((float) $detalle->igv)
                ->setTipAfeIgv($this->tipoAfectacionIgv($detalle->categoria_tributo))
                ->setTotalImpuestos((float) $detalle->igv);

            $detalles[] = $saleDetail;
        }

        $invoice->setDetails($detalles);

        // --- Leyenda (importe en letras) ---
        $legend = new Legend;
        $legend->setCode('1000')
            ->setValue($comprobante->importe_letras);
        $invoice->setLegends([$legend]);

        // --- Totales: el modelo de Greenter es el desglose por tipo de
        // operación (gravada/exonerada/inafecta/gratuita), no un
        // subtotal/total directo. Hoy BaseImponibleService solo produce
        // líneas gravadas (ver CategoriaTributo), así que todo el subtotal
        // es "operación gravada"; si algún día hay líneas exoneradas o
        // gratuitas, hay que sumarlas por separado acá.
        $invoice->setMtoOperGravadas((float) $comprobante->subtotal)
            ->setMtoIGV((float) $comprobante->igv)
            ->setTotalImpuestos((float) $comprobante->igv)
            ->setValorVenta((float) $comprobante->subtotal)
            ->setSubTotal((float) $comprobante->total)
            ->setMtoImpVenta((float) $comprobante->total);

        // --- Forma de pago: el catálogo 59 de SUNAT usa los literales
        // "Contado"/"Credito" (ver invoice2.1.xml.twig), no los códigos
        // 01/03 que guarda $comprobante->forma_pago.
        $paymentTerms = new PaymentTerms;
        $paymentTerms->setTipo($comprobante->forma_pago === '01' ? 'Contado' : 'Credito');
        $invoice->setFormaPago($paymentTerms);

        // PENDIENTE: $comprobante->medio_pago (efectivo/yape/...) y
        // $comprobante->codigo_detraccion no tienen dónde ir en esta versión
        // de greenter/xml — no existe Greenter\Model\Sale\PaymentMeans, y
        // PaymentTerms no tiene campos de detracción (sí existe
        // Invoice::setDetraccion(Detraction) para eso, sin usar todavía).

        return $invoice;
    }

    /**
     * Catálogo 07 de SUNAT (tipo de afectación del IGV) para `tipAfeIgv` de
     * cada línea. Solo Gravado (10) sale hoy de BaseImponibleService; los
     * demás van con el código más común de su categoría, a falta de un caso
     * real que confirme cuál corresponde.
     */
    private function tipoAfectacionIgv(CategoriaTributo $categoria): string
    {
        return match ($categoria) {
            CategoriaTributo::Gravado => '10', // Gravado - Operación Onerosa
            CategoriaTributo::Exonerado => '20', // Exonerado - Operación Onerosa
            CategoriaTributo::Gratuito => '21', // Gratuito - Operación Onerosa (vía bonificación)
            CategoriaTributo::Bonificacion => '21',
        };
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
            $comprobante->tipo_documento->value,
            $comprobante->serie,
            str_pad((string) $comprobante->correlativo, 8, '0', STR_PAD_LEFT)
        );
    }
}
