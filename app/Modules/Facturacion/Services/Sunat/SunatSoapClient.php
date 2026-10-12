<?php

namespace App\Modules\Facturacion\Services\Sunat;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\CredencialSunat;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\StatusCdrResult;
use Greenter\Ws\Builder\SoapBuilder;
use Greenter\Ws\Services\BillSender;
use Greenter\Ws\Services\ConsultCdrService;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Ws\Services\WsdlProvider;
use Illuminate\Support\Facades\Log;

/**
 * Implementación SOAP del cliente SUNAT usando Greenter/ws.
 *
 * Requiere las extensiones PHP: ext-soap, ext-dom, ext-openssl.
 * Los endpoints beta/producción vienen en SunatEndpoints de Greenter.
 *
 * La autenticación del SOAP (WS-Security) es usuario SOL + clave SOL — no
 * tiene nada que ver con el certificado digital, que solo firma el XML del
 * comprobante (ver FirmanteDigital). El usuario que SUNAT espera es
 * `RUC + usuarioSOL` concatenado (p. ej. "20602137903MODDATOS"), no el
 * usuario SOL suelto: `BillSender`/`ConsultCdrService`/`SummarySender` no
 * traen `setCertificate()` ni `setClaveSOL()` — esos nombres no existen en
 * greenter/ws 5.3; la credencial se arma con `SoapBuilder` y se inyecta con
 * `setClient()`, que es la API real de la librería instalada (verificado
 * contra el código fuente de vendor/greenter/ws).
 */
final class SunatSoapClient implements SunatClientInterface
{
    public function __construct(
        private readonly CredencialSunat $credencial,
        private readonly string $modo, // 'beta' | 'produccion'
    ) {}

    /**
     * {@inheritDoc}
     */
    public function enviarComprobante(Comprobante $comprobante, string $xmlContent, string $zipPath): array
    {
        try {
            $billSender = new BillSender;
            $billSender->setClient($this->client(SunatEndpoints::FE_BETA, SunatEndpoints::FE_PRODUCCION));

            /** @var BillResult $result */
            $result = $billSender->send($this->nombreArchivoXml($comprobante), $xmlContent);

            return [...$this->procesarResultadoBill($result), 'zip_path' => $zipPath];

        } catch (\Throwable $e) {
            Log::error('Error enviando comprobante a SUNAT', [
                'comprobante' => $comprobante->numero,
                'empresa' => $this->credencial->empresa_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'exito' => false,
                'cdr_codigo' => null,
                'cdr_descripcion' => null,
                'cdr_xml' => null,
                'cdr_digests' => null,
                'hash_cdr' => null,
                'codigo_seguridad' => null,
                'zip_path' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function consultarEstado(Comprobante $comprobante): array
    {
        try {
            // En producción, getStatusCdr vive en billConsultService (un
            // endpoint propio); en beta no hay ese servicio separado, se
            // consulta en el mismo billService.
            $statusService = new ConsultCdrService;
            $statusService->setClient($this->client(SunatEndpoints::FE_BETA, SunatEndpoints::FE_CONSULTA_CDR));

            /** @var StatusCdrResult $result */
            $result = $statusService->getStatusCdr(
                $this->credencial->empresa->ruc,
                $comprobante->tipo_documento->value,
                $comprobante->serie,
                $comprobante->correlativo
            );

            return $this->procesarResultadoStatus($result);

        } catch (\Throwable $e) {
            Log::error('Error consultando estado en SUNAT', [
                'comprobante' => $comprobante->numero,
                'error' => $e->getMessage(),
            ]);

            return [
                'exito' => false,
                'cdr_codigo' => null,
                'cdr_descripcion' => null,
                'cdr_xml' => null,
                'cdr_digests' => null,
                'hash_cdr' => null,
                'codigo_seguridad' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     *
     * PENDIENTE: el resumen diario necesita construir el XML "Resumen de
     * Boletas" (summary.xml.twig) con `Greenter\Xml\Builder\SummaryBuilder` a
     * partir de un `Greenter\Model\Summary\Summary` — un modelo y un builder
     * distintos de los de factura/boleta (Invoice/InvoiceBuilder), que este
     * código nunca llegó a construir: usaba `Greenter\Report\Report`, una
     * clase que no existe en ningún paquete instalado (ni greenter/xml ni
     * greenter/ws la declaran). SummarySender::send() en cambio sí es
     * correcto (mismo contrato que BillSender::send(): filename + contenido
     * XML ya armado), así que falta construir ese XML, no el envío.
     */
    public function enviarResumenDiario(array $comprobantes, string $zipPath): array
    {
        return [
            'exito' => false,
            'ticket' => null,
            'cdr_codigo' => null,
            'cdr_descripcion' => null,
            'cdr_xml' => null,
            'error' => 'Resumen diario no implementado: falta construir el XML del resumen (SummaryBuilder). Ver SunatSoapClient::enviarResumenDiario().',
        ];
    }

    /**
     * {@inheritDoc}
     *
     * PENDIENTE: SUNAT consulta el estado de un ticket (resumen diario o
     * comunicación de baja) con una operación `getStatus(ticket)` propia del
     * billConsultService, distinta de `getStatusCdr(ruc,tipo,serie,numero)`
     * que usa consultarEstado() para comprobantes. greenter/ws 5.3 no expone
     * esa operación por ticket en ConsultCdrService (solo las 4 por
     * ruc+tipo+serie+numero), así que esto sigue sin funcionar — no se debe
     * confiar en el resultado hasta resolverlo.
     */
    public function consultarResumenDiario(string $ticket): array
    {
        return [
            'exito' => false,
            'ticket' => null,
            'cdr_codigo' => null,
            'cdr_descripcion' => null,
            'cdr_xml' => null,
            'cdr_digests' => null,
            'error' => 'Consulta de resumen diario por ticket no implementada: greenter/ws no expone esa operación. Ver FirmanteDigital/SunatSoapClient.',
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function verificarCupo(): array
    {
        // greenter/ws 5.3 no expone getCredit(); no hay forma de consultar
        // el cupo de documentos con esta librería.
        return [
            'exito' => false,
            'documentos_disponibles' => null,
            'error' => 'Consulta de cupo no soportada por la librería SUNAT instalada.',
        ];
    }

    /**
     * Arma el SoapClient autenticado con usuario SOL (RUC + usuario, como
     * exige SUNAT) y clave SOL, apuntando al endpoint beta o producción. El
     * WSDL bundled con greenter/ws cambia si el endpoint es el
     * billConsultService (consulta de estado en producción) o el billService
     * normal (envío, resumen diario, y consulta en beta).
     */
    private function client(string $endpointBeta, string $endpointProduccion): SoapClient
    {
        $url = $this->modo === 'produccion' ? $endpointProduccion : $endpointBeta;
        $wsdl = $url === SunatEndpoints::FE_CONSULTA_CDR
            ? WsdlProvider::getConsultPath()
            : WsdlProvider::getBillPath();

        return (new SoapBuilder)
            ->setUrl($url)
            ->setWsdl($wsdl)
            ->setUser($this->credencial->empresa->ruc.$this->credencial->usuario_sol)
            ->setPassword($this->credencial->clave_sol)
            ->build();
    }

    private function nombreArchivoXml(Comprobante $comprobante): string
    {
        return sprintf(
            '%s-%s-%s-%s',
            $this->credencial->empresa->ruc,
            $comprobante->tipo_documento->value,
            $comprobante->serie,
            str_pad((string) $comprobante->correlativo, 8, '0', STR_PAD_LEFT)
        );
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

    private function procesarResultadoBill(BillResult $result): array
    {
        $exito = $result->isSuccess() ?? false;
        $cdr = $result->getCdrResponse();

        return [
            'exito' => $exito,
            'cdr_codigo' => $cdr?->getCode() ?? ($exito ? '0' : null),
            'cdr_descripcion' => $cdr?->getDescription() ?? ($exito ? 'Aceptado' : 'Error desconocido'),
            'cdr_xml' => $this->extraerCdrXml($result),
            'cdr_digests' => null, // CdrResponse no expone digest en esta versión de greenter/ws.
            'hash_cdr' => null,
            'codigo_seguridad' => $cdr?->getCode() ? $this->extraerCodigoSeguridad($cdr->getCode()) : null,
            'error' => $exito ? null : ($result->getError()?->getMessage() ?? ($cdr?->getDescription() ?? 'Error enviando a SUNAT')),
        ];
    }

    private function procesarResultadoStatus(StatusCdrResult $result): array
    {
        $exito = $result->isSuccess() ?? false;
        $cdr = $result->getCdrResponse();

        return [
            'exito' => $exito,
            'cdr_codigo' => $cdr?->getCode() ?? ($exito ? '0' : null),
            'cdr_descripcion' => $cdr?->getDescription() ?? ($exito ? 'Aceptado' : 'Error desconocido'),
            'cdr_xml' => $this->extraerCdrXml($result),
            'cdr_digests' => null, // CdrResponse no expone digest en esta versión de greenter/ws.
            'hash_cdr' => null,
            'codigo_seguridad' => $cdr?->getCode() ? $this->extraerCodigoSeguridad($cdr->getCode()) : null,
            'error' => $exito ? null : ($result->getError()?->getMessage() ?? ($cdr?->getDescription() ?? 'Error consultando estado')),
        ];
    }

    /**
     * Extrae el XML del CDR del ZIP de respuesta.
     */
    private function extraerCdrXml($result): ?string
    {
        $cdrZip = $result->getCdrZip();
        if (! $cdrZip) {
            return null;
        }

        $zip = new \ZipArchive;
        $tempZip = sys_get_temp_dir().'/cdr_'.uniqid().'.zip';
        file_put_contents($tempZip, $cdrZip);

        if ($zip->open($tempZip) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (str_ends_with($stat['name'], '.xml')) {
                    $xml = $zip->getFromIndex($i);
                    $zip->close();
                    @unlink($tempZip);

                    return $xml;
                }
            }
            $zip->close();
        }
        @unlink($tempZip);

        return null;
    }

    private function extraerCodigoSeguridad(string $cdrCodigo): ?string
    {
        if (preg_match('/^\d{4}$/', $cdrCodigo)) {
            return $cdrCodigo;
        }

        return null;
    }
}
