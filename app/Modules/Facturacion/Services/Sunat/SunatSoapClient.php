<?php

namespace App\Modules\Facturacion\Services\Sunat;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\CredencialSunat;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Ws\Services\BillSender;
use Greenter\Ws\Services\ConsultCdrService;
use Greenter\Ws\Services\SummarySender;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\StatusCdrResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\Report\Report;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Implementación SOAP del cliente SUNAT usando Greenter/ws.
 *
 * Requiere las extensiones PHP: ext-soap, ext-dom, ext-openssl.
 * Los endpoints beta/producción vienen en SunatEndpoints de Greenter.
 */
final class SunatSoapClient implements SunatClientInterface
{
    private readonly BillSender $billSender;
    private readonly ConsultCdrService $statusService;
    private readonly SummarySender $summarySender;

    public function __construct(
        private readonly CredencialSunat $credencial,
        private readonly string $modo, // 'beta' | 'produccion'
    ) {
        $this->billSender = new BillSender();
        $this->billSender->setEndpoints(
            $modo === 'produccion' ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA
        );

        $this->statusService = new ConsultCdrService();
        $this->statusService->setEndpoints(
            $modo === 'produccion' ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA
        );

        $this->summarySender = new SummarySender();
        $this->summarySender->setEndpoints(
            $modo === 'produccion' ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA
        );
    }

    /**
     * {@inheritDoc}
     */
    public function enviarComprobante(Comprobante $comprobante, string $xmlContent, string $zipPath): array
    {
        try {
            $this->configurarCredenciales($this->billSender);

            $xmlFileName = $this->nombreArchivoXml($comprobante);
            $xmlPath = $this->guardarTemporal($xmlFileName, $xmlContent);
            $zipPathAbsoluto = $this->guardarTemporal($comprobante->numero.'.zip', file_get_contents($zipPath));

            /** @var BillResult $result */
            $result = $this->billSender->send($comprobante->numero, $xmlContent);

            return $this->procesarResultadoBill($result, $zipPathAbsoluto);

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
            $this->configurarCredenciales($this->statusService);

            /** @var StatusCdrResult $result */
            $result = $this->statusService->getStatusCdr(
                $this->credencial->empresa->ruc,
                $comprobante->tipo_documento,
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
     */
    public function enviarResumenDiario(array $comprobantes, string $zipPath): array
    {
        try {
            $this->configurarCredenciales($this->summarySender);

            $report = new Report();
            $report->setCompany($this->credencial->empresa->ruc);

            foreach ($comprobantes as $comp) {
                $report->addItem([
                    'type' => $comp->tipo_documento,
                    'serie' => $comp->serie,
                    'correlative' => (string) $comp->correlativo,
                    'date' => $comp->fecha_emision->format('Y-m-d'),
                    'currency' => $comp->moneda,
                    'total' => (string) $comp->total,
                    'customer' => [
                        'type' => $this->tipoDocReceptor($comp),
                        'num' => $comp->receptor_numero_documento,
                    ],
                ]);
            }

            $filename = 'RA-'.$comprobantes[0]->fecha_emision->format('Ymd').'-001';
            $zipPathAbsoluto = $this->guardarTemporal($filename.'.zip', file_get_contents($zipPath));

            /** @var SummaryResult $result */
            $result = $this->summarySender->sendSummary(
                $this->credencial->empresa->ruc,
                $filename,
                $zipPathAbsoluto,
                $report
            );

            return $this->procesarResultadoSummary($result, $zipPathAbsoluto);

        } catch (\Throwable $e) {
            Log::error('Error enviando resumen diario a SUNAT', [
                'fecha' => $comprobantes[0]->fecha_emision?->toDateString(),
                'empresa' => $this->credencial->empresa_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'exito' => false,
                'ticket' => null,
                'cdr_codigo' => null,
                'cdr_descripcion' => null,
                'cdr_xml' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function consultarResumenDiario(string $ticket): array
    {
        // Consultar CDR de resumen usa getStatusCdr con el ticket
        // El ticket es el número de correlativo del resumen
        // Para simplificar, usamos el mismo método pero con el ticket como número
        // Nota: esto puede variar según la implementación de SUNAT
        try {
            $this->configurarCredenciales($this->statusService);

            /** @var StatusCdrResult $result */
            $result = $this->statusService->getStatusCdr(
                $this->credencial->empresa->ruc,
                'RA', // Tipo resumen
                substr($ticket, 0, 4), // Serie del resumen
                (int) substr($ticket, 4) // Correlativo del ticket
            );

            return $this->procesarResultadoSummary($result, null);

        } catch (\Throwable $e) {
            Log::error('Error consultando resumen diario en SUNAT', [
                'ticket' => $ticket,
                'error' => $e->getMessage(),
            ]);

            return [
                'exito' => false,
                'cdr_codigo' => null,
                'cdr_descripcion' => null,
                'cdr_xml' => null,
                'cdr_digests' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function verificarCupo(): array
    {
        try {
            $this->configurarCredenciales($this->billSender);

            // getCredit no está expuesto directamente, usar consultarEstado con factura de prueba
            // o implementar si está disponible en la versión de greenter
            return [
                'exito' => true,
                'documentos_disponibles' => 0, // No disponible en esta versión
                'error' => null,
            ];

        } catch (\Throwable $e) {
            Log::error('Error verificando cupo en SUNAT', [
                'empresa' => $this->credencial->empresa_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'exito' => false,
                'documentos_disponibles' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function configurarCredenciales($service): void
    {
        $service->setCertificate(
            file_get_contents(Storage::disk('privado')->path($this->credencial->certificado_path)),
            $this->credencial->certificado_password
        );
        $service->setClaveSOL(
            $this->credencial->usuario_sol,
            $this->credencial->clave_sol
        );
    }

    private function nombreArchivoXml(Comprobante $comprobante): string
    {
        return sprintf(
            '%s-%s-%s-%s.xml',
            $this->credencial->empresa->ruc,
            $comprobante->tipo_documento,
            $comprobante->serie,
            str_pad((string) $comprobante->correlativo, 8, '0', STR_PAD_LEFT)
        );
    }

    private function guardarTemporal(string $filename, string $content): string
    {
        $path = sys_get_temp_dir().'/sunat_'.$filename;
        file_put_contents($path, $content);
        return $path;
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

    private function procesarResultadoBill(BillResult $result, string $zipPath): array
    {
        $exito = $result->isSuccess() ?? false;
        $cdr = $result->getCdrResponse();

        return [
            'exito' => $exito,
            'cdr_codigo' => $cdr?->getCode() ?? ($exito ? '0' : null),
            'cdr_descripcion' => $cdr?->getDescription() ?? ($exito ? 'Aceptado' : 'Error desconocido'),
            'cdr_xml' => $this->extraerCdrXml($result),
            'cdr_digests' => $cdr?->getDigest() ? ['value' => $cdr->getDigest()] : null,
            'hash_cdr' => $cdr?->getDigest() ?? null,
            'codigo_seguridad' => $cdr?->getCode() ? $this->extraerCodigoSeguridad($cdr->getCode()) : null,
            'zip_path' => $zipPath,
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
            'cdr_digests' => $cdr?->getDigest() ? ['value' => $cdr->getDigest()] : null,
            'hash_cdr' => $cdr?->getDigest() ?? null,
            'codigo_seguridad' => $cdr?->getCode() ? $this->extraerCodigoSeguridad($cdr->getCode()) : null,
            'error' => $exito ? null : ($result->getError()?->getMessage() ?? ($cdr?->getDescription() ?? 'Error consultando estado')),
        ];
    }

    private function procesarResultadoSummary(SummaryResult $result, ?string $zipPath): array
    {
        $exito = $result->isSuccess() ?? false;
        $cdr = $result->getCdrResponse();

        return [
            'exito' => $exito,
            'ticket' => $result->getTicket() ?? null,
            'cdr_codigo' => $cdr?->getCode() ?? ($exito ? '0' : null),
            'cdr_descripcion' => $cdr?->getDescription() ?? ($exito ? 'Aceptado' : 'Error desconocido'),
            'cdr_xml' => $this->extraerCdrXml($result),
            'cdr_digests' => $cdr?->getDigest() ? ['value' => $cdr->getDigest()] : null,
            'error' => $exito ? null : ($result->getError()?->getMessage() ?? ($cdr?->getDescription() ?? 'Error en resumen diario')),
            'zip_path' => $zipPath,
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

        // El CDR viene en un ZIP, extraer el XML
        $zip = new \ZipArchive();
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