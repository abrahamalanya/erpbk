<?php

namespace App\Modules\Facturacion\Services\Sunat;

use App\Modules\Facturacion\Models\Comprobante;

/**
 * Interfaz del cliente SUNAT.
 *
 * Oculta el detalle del protocolo (SOAP vs REST vs proveedor externo) para que
 * el dominio pueda testearse con un fake y cambiar de canal (Opción A vs B del
 * FACTURACION.md §4.7) sin reescribir la lógica de negocio.
 */
interface SunatClientInterface
{
    /**
     * Envía un comprobante individual a SUNAT (sendBill).
     *
     * @return array{
     *     exito: bool,
     *     cdr_codigo: ?string,
     *     cdr_descripcion: ?string,
     *     cdr_xml: ?string,
     *     cdr_digests: ?array,
     *     hash_cdr: ?string,
     *     codigo_seguridad: ?string,
     *     zip_path: ?string,
     *     error: ?string
     * }
     */
    public function enviarComprobante(Comprobante $comprobante, string $xmlContent, string $zipPath): array;

    /**
     * Consulta el estado de un comprobante ya enviado (getStatus).
     *
     * @return array{
     *     exito: bool,
     *     cdr_codigo: ?string,
     *     cdr_descripcion: ?string,
     *     cdr_xml: ?string,
     *     cdr_digests: ?array,
     *     hash_cdr: ?string,
     *     codigo_seguridad: ?string,
     *     error: ?string
     * }
     */
    public function consultarEstado(Comprobante $comprobante): array;

    /**
     * Envía el resumen diario de boletas y comunicaciones de baja (sendSummary).
     *
     * @param array<int, Comprobante> $comprobantes
     * @return array{
     *     exito: bool,
     *     ticket: ?string,
     *     cdr_codigo: ?string,
     *     cdr_descripcion: ?string,
     *     cdr_xml: ?string,
     *     error: ?string
     * }
     */
    public function enviarResumenDiario(array $comprobantes, string $zipPath): array;

    /**
     * Obtiene el CDR de un resumen diario ya enviado (getStatus con ticket).
     *
     * @return array{
     *     exito: bool,
     *     cdr_codigo: ?string,
     *     cdr_descripcion: ?string,
     *     cdr_xml: ?string,
     *     cdr_digests: ?array,
     *     error: ?string
     * }
     */
    public function consultarResumenDiario(string $ticket): array;

    /**
     * Verifica el cupo de documentos disponibles (getCredit).
     *
     * @return array{
     *     exito: bool,
     *     documentos_disponibles: ?int,
     *     error: ?string
     * }
     */
    public function verificarCupo(): array;
}