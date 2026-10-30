<?php

namespace App\Modules\Sistemas\Services;

use ZipArchive;

/**
 * Inspecciona el contenido real de un APK subido (un APK es, en el fondo, un
 * ZIP): confirma estructura (formato/integridad), presencia de firma, extrae
 * la huella del certificado del firmante cuando el esquema es v2, y decodifica
 * el AndroidManifest.xml binario (AXML) para leer applicationId/versionCode/
 * versionName reales — nunca se confía en lo que el admin escriba a mano en
 * el formulario, porque eso es exactamente lo que permitía publicar una
 * versión con metadata distinta al contenido real del archivo (causa del
 * ciclo de "actualización" que nunca se instala).
 */
final class ApkInspectorService
{
    private const EOCD_SIGNATURE = "\x50\x4b\x05\x06";

    private const EOCD_MIN_SIZE = 22;

    private const EOCD_MAX_COMMENT = 65535;

    private const SIG_BLOCK_MAGIC = 'APK Sig Block 42';

    /** ID del bloque "APK Signature Scheme v2" dentro del APK Signing Block. */
    private const ID_V2_SIGNATURE = 0x7109871A;

    /** Chunk types del formato binario AXML (ver frameworks/base de AOSP, ResourceTypes.h). */
    private const RES_STRING_POOL_TYPE = 0x0001;

    private const RES_XML_START_ELEMENT_TYPE = 0x0102;

    /** Res_value::dataType para un valor que es un índice al pool de cadenas. */
    private const TYPE_STRING = 0x03;

    /**
     * @return array{es_apk: bool, firmado: bool, certificado_sha256: ?string, package: ?string, version_code: ?int, version_name: ?string}
     */
    public function inspeccionar(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['es_apk' => false, 'firmado' => false, 'certificado_sha256' => null, 'package' => null, 'version_code' => null, 'version_name' => null];
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
        $manifiesto = $esApk ? $this->leerManifiesto($zip) : ['package' => null, 'version_code' => null, 'version_name' => null];
        $zip->close();

        $bloque = $this->localizarBloqueFirmaV2V3($path);

        return [
            'es_apk' => $esApk,
            'firmado' => $firmadoV1 || $bloque !== null,
            'certificado_sha256' => $bloque !== null ? $this->extraerCertificadoSha256($bloque) : null,
            ...$manifiesto,
        ];
    }

    /**
     * Lee el AndroidManifest.xml binario (AXML) del APK y extrae los
     * atributos reales del elemento raíz `<manifest>`: package, versionCode,
     * versionName. Nunca lanza: ante cualquier estructura inesperada o
     * corrupta devuelve valores null, dejando a ApkVersionService decidir si
     * eso basta para rechazar la subida.
     *
     * @return array{package: ?string, version_code: ?int, version_name: ?string}
     */
    private function leerManifiesto(ZipArchive $zip): array
    {
        $vacio = ['package' => null, 'version_code' => null, 'version_name' => null];

        $binario = $zip->getFromName('AndroidManifest.xml');
        if ($binario === false || strlen($binario) < 8) {
            return $vacio;
        }

        try {
            return $this->parsearManifiestoBinario($binario) ?? $vacio;
        } catch (\Throwable) {
            return $vacio;
        }
    }

    /**
     * @return array{package: ?string, version_code: ?int, version_name: ?string}|null
     */
    private function parsearManifiestoBinario(string $d): ?array
    {
        // Chunk raíz RES_XML_TYPE: type(u16) + headerSize(u16) + size(u32) = 8 bytes de cabecera.
        if (strlen($d) < 8 || $this->u16($d, 0) !== 0x0003) {
            return null;
        }

        $pos = 8;
        if ($this->u16($d, $pos) !== self::RES_STRING_POOL_TYPE) {
            return null;
        }

        $pool = $this->leerPoolDeCadenas($d, $pos);
        if ($pool === null) {
            return null;
        }
        [$cadenas, $pos] = $pool;

        // Avanza saltando chunks (resource map, namespaces) hasta el primer
        // elemento (el elemento raíz <manifest>), usando cada chunk.size para
        // saltar sin necesitar entender su contenido.
        $limite = strlen($d);
        while ($pos + 8 <= $limite) {
            $tipo = $this->u16($d, $pos);
            $tamanoChunk = $this->u32($d, $pos + 4);
            if ($tamanoChunk < 8 || $pos + $tamanoChunk > $limite) {
                return null;
            }
            if ($tipo === self::RES_XML_START_ELEMENT_TYPE) {
                break;
            }
            $pos += $tamanoChunk;
        }
        if ($pos + 8 > $limite || $this->u16($d, $pos) !== self::RES_XML_START_ELEMENT_TYPE) {
            return null;
        }

        // ResXMLTree_node (8 cabecera + lineNumber u32 + comment u32 = 16) seguido de ResXMLTree_attrExt.
        $attrExtStart = $pos + 16;
        if ($attrExtStart + 20 > $limite) {
            return null;
        }
        $attributeStart = $this->u16($d, $attrExtStart + 8);
        $attributeSize = $this->u16($d, $attrExtStart + 10);
        $attributeCount = $this->u16($d, $attrExtStart + 12);
        if ($attributeSize < 20 || $attributeCount > 1000) {
            return null;
        }

        $resultado = ['package' => null, 'version_code' => null, 'version_name' => null];
        $base = $attrExtStart + $attributeStart;
        for ($i = 0; $i < $attributeCount; $i++) {
            $offset = $base + $i * $attributeSize;
            if ($offset + 20 > $limite) {
                return null;
            }

            $nombreIdx = $this->u32($d, $offset + 4);
            $tipoDato = ord($d[$offset + 15]);
            $dato = $this->u32($d, $offset + 16);
            $nombre = $cadenas[$nombreIdx] ?? null;
            if ($nombre === null) {
                continue;
            }

            $valor = $tipoDato === self::TYPE_STRING ? ($cadenas[$dato] ?? null) : $dato;

            match ($nombre) {
                'package' => $resultado['package'] = is_string($valor) ? $valor : null,
                'versionCode' => $resultado['version_code'] = is_int($valor) ? $valor : null,
                'versionName' => $resultado['version_name'] = is_string($valor) ? $valor : null,
                default => null,
            };
        }

        return $resultado;
    }

    /**
     * Lee el chunk de pool de cadenas (ResStringPool) que sigue siempre a la
     * cabecera raíz de un AXML, y decodifica todas sus cadenas (UTF-8 o
     * UTF-16, según el flag del chunk).
     *
     * @return array{0: array<int, string>, 1: int}|null [cadenas por índice, offset del siguiente chunk]
     */
    private function leerPoolDeCadenas(string $d, int $inicio): ?array
    {
        $limite = strlen($d);
        if ($inicio + 28 > $limite) {
            return null;
        }

        $headerSize = $this->u16($d, $inicio + 2);
        $tamanoChunk = $this->u32($d, $inicio + 4);
        $cantidad = $this->u32($d, $inicio + 8);
        $flags = $this->u32($d, $inicio + 16);
        $stringsStart = $this->u32($d, $inicio + 20);
        $esUtf8 = ($flags & 0x100) !== 0;

        if ($tamanoChunk < 28 || $inicio + $tamanoChunk > $limite || $cantidad > 10000) {
            return null;
        }

        $offsets = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $offsetPos = $inicio + $headerSize + $i * 4;
            if ($offsetPos + 4 > $limite) {
                return null;
            }
            $offsets[] = $this->u32($d, $offsetPos);
        }

        $cadenas = [];
        foreach ($offsets as $i => $off) {
            $abs = $inicio + $stringsStart + $off;
            if ($abs < 0 || $abs >= $limite) {
                return null;
            }
            $cadenas[$i] = $esUtf8 ? $this->leerCadenaUtf8($d, $abs) : $this->leerCadenaUtf16($d, $abs);
        }

        return [$cadenas, $inicio + $tamanoChunk];
    }

    private function leerCadenaUtf16(string $d, int $pos): string
    {
        $limite = strlen($d);
        if ($pos + 2 > $limite) {
            return '';
        }
        $ancho = $this->u16($d, $pos);
        if (($ancho & 0x8000) !== 0) {
            if ($pos + 4 > $limite) {
                return '';
            }
            $largo = (($ancho & 0x7FFF) << 16) | $this->u16($d, $pos + 2);
            $pos += 4;
        } else {
            $largo = $ancho;
            $pos += 2;
        }
        if ($largo < 0 || $pos + $largo * 2 > $limite) {
            return '';
        }

        return mb_convert_encoding(substr($d, $pos, $largo * 2), 'UTF-8', 'UTF-16LE');
    }

    private function leerCadenaUtf8(string $d, int $pos): string
    {
        $limite = strlen($d);
        if ($pos + 1 > $limite) {
            return '';
        }
        // Primero el largo en unidades UTF-16 (1-2 bytes), luego el largo real en bytes UTF-8 (1-2 bytes).
        $b = ord($d[$pos]);
        $pos += ($b & 0x80) !== 0 ? 2 : 1;
        if ($pos >= $limite) {
            return '';
        }
        $b2 = ord($d[$pos]);
        if (($b2 & 0x80) !== 0) {
            if ($pos + 1 >= $limite) {
                return '';
            }
            $largo = (($b2 & 0x7F) << 8) | ord($d[$pos + 1]);
            $pos += 2;
        } else {
            $largo = $b2;
            $pos += 1;
        }
        if ($largo < 0 || $pos + $largo > $limite) {
            return '';
        }

        return substr($d, $pos, $largo);
    }

    private function u16(string $d, int $o): int
    {
        return unpack('v', substr($d, $o, 2))[1];
    }

    private function u32(string $d, int $o): int
    {
        return unpack('V', substr($d, $o, 4))[1];
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
