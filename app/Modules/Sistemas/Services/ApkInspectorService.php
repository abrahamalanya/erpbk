<?php

namespace App\Modules\Sistemas\Services;

use ZipArchive;

/**
 * Inspecciona el contenido real de un APK subido (un APK es, en el fondo, un
 * ZIP). No parsea el binario de AndroidManifest.xml (requeriría un decoder de
 * AXML/ASN.1 que no se puede validar sin un APK real de EAS a mano) — solo
 * confirma estructura (formato/integridad), presencia de firma, y extrae la
 * huella del certificado del firmante cuando el esquema es v2. La
 * compatibilidad de applicationId/versionCode contra la aplicación existente
 * la valida ApkVersionService con la metadata declarada por el admin.
 */
final class ApkInspectorService
{
    private const EOCD_SIGNATURE = "\x50\x4b\x05\x06";

    private const EOCD_MIN_SIZE = 22;

    private const EOCD_MAX_COMMENT = 65535;

    private const SIG_BLOCK_MAGIC = 'APK Sig Block 42';

    /** ID del bloque "APK Signature Scheme v2" dentro del APK Signing Block. */
    private const ID_V2_SIGNATURE = 0x7109871A;

    /**
     * @return array{es_apk: bool, firmado: bool, certificado_sha256: ?string}
     */
    public function inspeccionar(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['es_apk' => false, 'firmado' => false, 'certificado_sha256' => null];
        }

        $esApk = $zip->locateName('AndroidManifest.xml') !== false && $zip->locateName('classes.dex') !== false;
        $firmadoV1 = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            if ($nombre !== false && preg_match('#^META-INF/[^/]+\.(RSA|DSA|EC)$#i', $nombre) === 1) {
                $firmadoV1 = true;
                break;
            }
        }
        $zip->close();

        $bloque = $this->localizarBloqueFirmaV2V3($path);

        return [
            'es_apk' => $esApk,
            'firmado' => $firmadoV1 || $bloque !== null,
            'certificado_sha256' => $bloque !== null ? $this->extraerCertificadoSha256($bloque) : null,
        ];
    }

    /**
     * Localiza el APK Signing Block (v2/v3) calculando su offset real a
     * partir del End Of Central Directory (EOCD) del ZIP, en vez de adivinar
     * buscando su "magic" dentro de una ventana fija de bytes al final del
     * archivo — en un APK con muchos archivos (cualquier app Expo/React
     * Native, con .so por cada ABI, Hermes, recursos) el directorio central
     * del ZIP fácilmente supera cualquier ventana fija razonable y empuja el
     * bloque de firma fuera de ella, dando un falso negativo de "no firmado".
     *
     * Devuelve el bloque completo (tamaño líder + contenido + tamaño cola +
     * magic) o null si no hay un bloque válido.
     */
    private function localizarBloqueFirmaV2V3(string $path): ?string
    {
        $tamano = filesize($path);
        if ($tamano === false || $tamano < self::EOCD_MIN_SIZE) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $eocdOffset = $this->buscarEocd($handle, $tamano);
            if ($eocdOffset === null) {
                return null;
            }

            fseek($handle, $eocdOffset + 16);
            $cdOffsetBytes = fread($handle, 4);
            if ($cdOffsetBytes === false || strlen($cdOffsetBytes) !== 4) {
                return null;
            }
            $cdOffset = unpack('V', $cdOffsetBytes)[1];

            if ($cdOffset < 24) {
                return null;
            }

            fseek($handle, $cdOffset - 24);
            $cola = fread($handle, 24);
            if ($cola === false || strlen($cola) !== 24) {
                return null;
            }

            if (substr($cola, 8, 16) !== self::SIG_BLOCK_MAGIC) {
                return null;
            }

            $tamanoBloque = unpack('P', substr($cola, 0, 8))[1];
            // $tamanoBloque excluye el campo líder de 8 bytes; el bloque
            // completo en disco mide 8 + $tamanoBloque bytes.
            $inicioBloque = $cdOffset - 8 - $tamanoBloque;
            if ($inicioBloque < 0 || $tamanoBloque < 24) {
                return null;
            }

            fseek($handle, $inicioBloque);
            $tamanoLiderBytes = fread($handle, 8);
            if ($tamanoLiderBytes === false || strlen($tamanoLiderBytes) !== 8) {
                return null;
            }

            // El tamaño se repite al inicio y al final del bloque — si no
            // coincide, lo que encontramos antes del directorio central no es
            // realmente el bloque de firma (falso positivo del magic).
            if (unpack('P', $tamanoLiderBytes)[1] !== $tamanoBloque) {
                return null;
            }

            fseek($handle, $inicioBloque);
            $bloque = fread($handle, 8 + $tamanoBloque);

            return ($bloque !== false && strlen($bloque) === 8 + $tamanoBloque) ? $bloque : null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Busca la firma de EOCD desde el final del archivo, validando que el
     * campo de longitud de comentario cuadre exactamente con los bytes
     * restantes — sin esto, un ZIP cuyo comentario contenga por casualidad
     * esos 4 bytes daría un falso positivo (mismo criterio que usan las
     * implementaciones de referencia de Android para esto).
     */
    private function buscarEocd($handle, int $tamano): ?int
    {
        $aLeer = min($tamano, self::EOCD_MIN_SIZE + self::EOCD_MAX_COMMENT);
        fseek($handle, -$aLeer, SEEK_END);
        $cola = fread($handle, $aLeer);
        if ($cola === false) {
            return null;
        }

        $inicioCola = $tamano - $aLeer;
        $limite = strlen($cola);

        while (($pos = strrpos(substr($cola, 0, $limite), self::EOCD_SIGNATURE)) !== false) {
            if ($pos + self::EOCD_MIN_SIZE <= strlen($cola)) {
                $comentarioLen = unpack('v', substr($cola, $pos + 20, 2))[1];
                $restante = strlen($cola) - ($pos + self::EOCD_MIN_SIZE);

                if ($comentarioLen === $restante) {
                    return $inicioCola + $pos;
                }
            }

            $limite = $pos;
        }

        return null;
    }

    /**
     * Busca el ID-value pair del esquema v2 (0x7109871a) dentro del bloque
     * de firma y extrae la huella SHA-256 del primer certificado del primer
     * firmante. Devuelve null ante cualquier estructura inesperada (v1-only,
     * v3-only sin v2, o un formato que no se pudo parsear) — es informativo,
     * nunca debe bloquear la subida del APK.
     */
    private function extraerCertificadoSha256(string $bloque): ?string
    {
        $contenido = substr($bloque, 8, -24);
        $len = strlen($contenido);
        $offset = 0;

        while ($offset + 12 <= $len) {
            $tamanoPar = unpack('P', substr($contenido, $offset, 8))[1];
            if ($tamanoPar < 4 || $offset + 8 + $tamanoPar > $len) {
                return null;
            }

            $id = unpack('V', substr($contenido, $offset + 8, 4))[1];

            if ($id === self::ID_V2_SIGNATURE) {
                return $this->certificadoDesdeFirmanteV2(substr($contenido, $offset + 12, $tamanoPar - 4));
            }

            $offset += 8 + $tamanoPar;
        }

        return null;
    }

    /**
     * @param  string  $value  Secuencia de firmantes del bloque v2 (ver
     *                         "APK Signature Scheme v2" de AOSP): todo son
     *                         bloques uint32-length-prefixed anidados.
     */
    private function certificadoDesdeFirmanteV2(string $value): ?string
    {
        $secuenciaFirmantes = $this->leerBloqueLP($value, 0);
        if ($secuenciaFirmantes === null) {
            return null;
        }

        $primerFirmante = $this->leerBloqueLP($secuenciaFirmantes[0], 0);
        if ($primerFirmante === null) {
            return null;
        }

        $signedData = $this->leerBloqueLP($primerFirmante[0], 0);
        if ($signedData === null) {
            return null;
        }
        $datosFirmados = $signedData[0];

        // Dentro de signed data: digests (se salta) y luego certificados.
        $digests = $this->leerBloqueLP($datosFirmados, 0);
        if ($digests === null) {
            return null;
        }

        $certificados = $this->leerBloqueLP($datosFirmados, $digests[1]);
        if ($certificados === null) {
            return null;
        }

        $primerCertificado = $this->leerBloqueLP($certificados[0], 0);
        if ($primerCertificado === null) {
            return null;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($primerCertificado[0]), 64, "\n")."-----END CERTIFICATE-----\n";
        $huella = openssl_x509_fingerprint($pem, 'sha256');

        return $huella !== false ? $huella : null;
    }

    /**
     * Lee un bloque length-prefixed (uint32 LE) en $data a partir de $pos.
     *
     * @return array{0: string, 1: int}|null [bytes del bloque, posición siguiente]
     */
    private function leerBloqueLP(string $data, int $pos): ?array
    {
        if ($pos < 0 || $pos + 4 > strlen($data)) {
            return null;
        }

        $largo = unpack('V', substr($data, $pos, 4))[1];
        if ($pos + 4 + $largo > strlen($data)) {
            return null;
        }

        return [substr($data, $pos + 4, $largo), $pos + 4 + $largo];
    }
}
