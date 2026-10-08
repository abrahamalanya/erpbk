<?php

namespace App\Modules\Facturacion\Services\Sunat;

use Greenter\Model\Response\CdrResponse;

/**
 * Parser del CDR (Comprobante de Recepción) de SUNAT.
 *
 * Trabaja directamente con el objeto CdrResponse de Greenter.
 */
final class CdrParser
{
    /**
     * Parsea el CDR desde un objeto CdrResponse.
     *
     * @return array{
     *     codigo: string,
     *     descripcion: string,
     *     notas: array<string>,
     *     digest: ?string,
     *     signature_value: ?string,
     *     fecha_recepcion: ?string,
     *     hora_recepcion: ?string,
     *     exito: bool
     * }
     */
    public function parse(CdrResponse $response): array
    {
        return [
            'codigo' => $response->getCode() ?? '9999',
            'descripcion' => $response->getDescription() ?? 'Sin descripción',
            'notas' => $response->getNotes() ?? [],
            'digest' => $response->getDigest(),
            'signature_value' => $response->getSignatureValue() ?? null,
            'fecha_recepcion' => $response->getFechaRecepcion() ?? null,
            'hora_recepcion' => $response->getHoraRecepcion() ?? null,
            'exito' => $response->isSuccess(),
        ];
    }

    /**
     * Parsea el CDR desde XML string (fallback si no hay objeto CdrResponse).
     */
    public function parseXml(string $cdrXml): array
    {
        // Parseo básico del XML del CDR
        $dom = new \DOMDocument();
        $dom->loadXML($cdrXml);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        $codigo = $xpath->evaluate('string(//cbc:ResponseCode)');
        $descripcion = $xpath->evaluate('string(//cbc:Description)');
        $digest = $xpath->evaluate('string(//ds:DigestValue)');

        return [
            'codigo' => $codigo ?: '9999',
            'descripcion' => $descripcion ?: 'Sin descripción',
            'notas' => [],
            'digest' => $digest ?: null,
            'signature_value' => null,
            'fecha_recepcion' => null,
            'hora_recepcion' => null,
            'exito' => $codigo === '0',
        ];
    }

    /**
     * Extrae el código de seguridad del CDR.
     * El código de seguridad son los 4 primeros dígitos del hash SHA-1 del CDR
     * (o del digest si está disponible), representados en hexadecimal mayúscula.
     */
    public function extraerCodigoSeguridad(CdrResponse $response): ?string
    {
        if ($response->getDigest()) {
            // El digest ya viene en base64 del XML, lo convertimos a hex
            // y tomamos los primeros 4 caracteres en mayúscula
            $hex = bin2hex(base64_decode($response->getDigest()));
            return strtoupper(substr($hex, 0, 4));
        }

        // Fallback: si no hay digest, usar el código de respuesta si es 4 dígitos
        if (preg_match('/^\d{4}$/', $response->getCode() ?? '')) {
            return $response->getCode();
        }

        return null;
    }

    /**
     * Extrae el código de seguridad desde XML.
     */
    public function extraerCodigoSeguridadXml(string $cdrXml): ?string
    {
        $parsed = $this->parseXml($cdrXml);

        if ($parsed['digest']) {
            $hex = bin2hex(base64_decode($parsed['digest']));
            return strtoupper(substr($hex, 0, 4));
        }

        if (preg_match('/^\d{4}$/', $parsed['codigo'])) {
            return $parsed['codigo'];
        }

        return null;
    }

    /**
     * Verifica si el CDR indica éxito (código 0).
     */
    public function esExitoso(CdrResponse $response): bool
    {
        return $response->isSuccess() && $response->getCode() === '0';
    }

    /**
     * Verifica si el CDR tiene observaciones (código 2xxx).
     */
    public function tieneObservaciones(CdrResponse $response): bool
    {
        return $response->isSuccess() && str_starts_with($response->getCode() ?? '', '2');
    }

    /**
     * Verifica si el CDR indica rechazo (código 4xxx).
     */
    public function esRechazado(CdrResponse $response): bool
    {
        return ! $response->isSuccess() || str_starts_with($response->getCode() ?? '', '4');
    }
}