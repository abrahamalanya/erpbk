<?php

namespace App\Modules\Sistemas\Services;

use ZipArchive;

/**
 * Inspecciona el contenido real de un APK subido (un APK es, en el fondo, un
 * ZIP). No parsea el binario de AndroidManifest.xml (requeriría un decoder de
 * AXML/ASN.1 que no se puede validar sin un APK real de EAS a mano) — solo
 * confirma estructura (formato/integridad) y presencia de firma. La
 * compatibilidad de applicationId/versionCode contra la aplicación existente
 * la valida ApkVersionService con la metadata declarada por el admin.
 */
final class ApkInspectorService
{
    /**
     * @return array{es_apk: bool, firmado: bool}
     */
    public function inspeccionar(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['es_apk' => false, 'firmado' => false];
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

        return ['es_apk' => $esApk, 'firmado' => $firmadoV1 || $this->tieneBloqueFirmaV2V3($path)];
    }

    /**
     * Esquemas v2/v3 de firma de APK: un bloque ubicado justo antes del
     * directorio central del ZIP, cerrado con el magic "APK Sig Block 42".
     * Buscar el magic en la cola del archivo (en vez de calcular el offset
     * exacto recorriendo el bloque hacia atrás) es una verificación de
     * presencia suficiente para este caso de uso.
     */
    private function tieneBloqueFirmaV2V3(string $path): bool
    {
        $tamano = filesize($path);
        if ($tamano === false) {
            return false;
        }

        $aLeer = min($tamano, 65536);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        fseek($handle, -$aLeer, SEEK_END);
        $cola = fread($handle, $aLeer);
        fclose($handle);

        return $cola !== false && str_contains($cola, 'APK Sig Block 42');
    }
}
