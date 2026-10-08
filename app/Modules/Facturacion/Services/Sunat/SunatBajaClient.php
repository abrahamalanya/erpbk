<?php

namespace App\Modules\Facturacion\Services\Sunat;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\CredencialSunat;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Ws\Services\SunatSendService;
use Greenter\Report\Report;
use DomainException;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para comunicación de baja (anulación) y notas de crédito.
 *
 * Extiende la funcionalidad de SunatSoapClient para operaciones
 * que usan sendSummary (baja, resumen diario).
 */
final class SunatBajaClient
{
    private readonly SunatSendService $service;

    public function __construct(
        private readonly CredencialSunat $credencial,
        private readonly string $modo,
    ) {
        $this->service = new SunatSendService();
        $this->service->setEndpoints(
            $modo === 'produccion' ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA
        );
    }

    /**
     * Comunica la baja de un comprobante ya aceptado.
     *
     * Usa sendSummary con tipo de documento = baja (según catálogo 01).
     * Requiere que el comprobante esté en estado Aceptado u Observado.
     *
     * @return array{
     *     exito: bool,
     *     ticket: ?string,
     *     cdr_codigo: ?string,
     *     cdr_descripcion: ?string,
     *     cdr_xml: ?string,
     *     error: ?string
     * }
     */
    public function comunicarBaja(Comprobante $comprobante, string $motivo): array
    {
        try {
            $this->configurarCredenciales();

            // Validaciones previas
            if (! in_array($comprobante->estado->value, ['aceptado', 'observado'], true)) {
                return [
                    'exito' => false,
                    'ticket' => null,
                    'cdr_codigo' => null,
                    'cdr_descripcion' => 'Solo se puede comunicar baja de comprobantes aceptados u observados',
                    'cdr_xml' => null,
                    'error' => 'Estado inválido para baja',
                ];
            }

            // Generar ZIP con la comunicación de baja
            $zipPath = $this->generarZipBaja($comprobante, $motivo);

            // Enviar como resumen diario con un solo item (baja)
            $report = new Report();
            $report->setCompany($this->credencial->empresa->ruc);

            // Para comunicación de baja, el tipo es el mismo del comprobante
            // pero se marca como 'void' en el reporte
            $report->addItem([
                'type' => $comprobante->tipo_documento, // 01, 03
                'serie' => $comprobante->serie,
                'correlative' => (string) $comprobante->correlativo,
                'date' => $comprobante->fecha_emision->format('Y-m-d'),
                'currency' => $comprobante->moneda,
                'total' => (string) $comprobante->total,
                'customer' => [
                    'type' => $this->tipoDocReceptor($comprobante),
                    'num' => $comprobante->receptor_numero_documento,
                ],
                'void' => true, // Marca como comunicación de baja
                'void_description' => $motivo,
            ]);

            $filename = 'RA-BAJA-'.$comprobante->fecha_emision->format('Ymd').'-'.$comprobante->serie.$comprobante->correlativo;

            $response = $this->service->sendSummary(
                ruc: $this->credencial->empresa->ruc,
                filename: $filename,
                zipFile: $zipPath,
                report: $report,
            );

            return $this->procesarRespuestaBaja($response, $zipPath);

        } catch (\Throwable $e) {
            Log::error('Error comunicando baja en SUNAT', [
                'comprobante' => $comprobante->numero,
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
     * Consulta el CDR de una comunicación de baja o resumen diario ya enviado.
     */
    public function consultarCdrResumen(string $ticket): array
    {
        try {
            $this->configurarCredenciales();

            $response = $this->service->getStatusCdr(
                ruc: $this->credencial->empresa->ruc,
                ticket: $ticket,
            );

            $exito = $response->isSuccess();
            $cdr = $response->getCdrResponse();

            return [
                'exito' => $exito,
                'cdr_codigo' => $cdr?->getCode() ?? ($exito ? '0' : null),
                'cdr_descripcion' => $cdr?->getDescription() ?? ($exito ? 'Aceptado' : 'Error'),
                'cdr_xml' => $cdr?->getXml() ?? null,
                'cdr_digests' => $cdr?->getDigest() ? ['value' => $cdr->getDigest()] : null,
                'error' => $exito ? null : ($cdr?->getDescription() ?? 'Error consultando CDR'),
            ];

        } catch (\Throwable $e) {
            Log::error('Error consultando CDR resumen/baja', [
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

    private function configurarCredenciales(): void
    {
        $this->service->setCertificate(
            file_get_contents(\Illuminate\Support\Facades\Storage::disk('privado')->path($this->credencial->certificado_path)),
            $this->credencial->certificado_password
        );
        $this->service->setClaveSOL(
            $this->credencial->usuario_sol,
            $this->credencial->clave_sol
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

    private function generarZipBaja(Comprobante $comprobante, string $motivo): string
    {
        // El ZIP real se genera internamente en Greenter al llamar sendSummary
        // Solo necesitamos un placeholder
        return sys_get_temp_dir().'/baja-'.$comprobante->numero.'.zip';
    }

    private function procesarRespuestaBaja(\Greenter\Ws\SummaryResponse $response, string $zipPath): array
    {
        $exito = $response->isSuccess();
        $cdr = $response->getCdrResponse();

        return [
            'exito' => $exito,
            'ticket' => $response->getTicket() ?? null,
            'cdr_codigo' => $cdr?->getCode() ?? ($exito ? '0' : null),
            'cdr_descripcion' => $cdr?->getDescription() ?? ($exito ? 'Baja comunicada' : 'Error'),
            'cdr_xml' => $cdr?->getXml() ?? null,
            'error' => $exito ? null : ($cdr?->getDescription() ?? 'Error comunicando baja'),
        ];
    }
}