<?php

namespace App\Modules\Facturacion\Services\Sunat;

use DomainException;
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use Throwable;

/**
 * Firma digital de comprobantes: toma el XML UBL sin firmar (que ya trae el
 * `<ext:UBLExtensions><ext:ExtensionContent/></ext:UBLExtensions>` vacío de
 * fábrica de las plantillas de greenter/xml) y el certificado `.pfx` de la
 * empresa, y devuelve el XML con la firma SUNAT insertada ahí mismo.
 *
 * Es el paso que FACTURACION.md §5.7 dejaba pendiente: existían
 * `greenter/xmldsig` (la librería que firma) y `credenciales_sunat` (donde
 * vive el certificado), pero nada los conectaba — `UblBuilder` enviaba el
 * XML sin firmar y SUNAT lo rechaza siempre así.
 *
 * ## El problema de los certificados antiguos
 *
 * Los `.pfx` que emite RENIEC/ECEP para SUNAT suelen cifrar el certificado
 * (no la llave privada) con `pbeWithSHA1And40BitRC2-CBC`, un algoritmo que
 * OpenSSL 3 ya no trae activado por defecto — `openssl_pkcs12_read()` falla
 * con "unsupported" aunque la contraseña sea correcta. Verificado con un
 * certificado real de SUNAT: PHP no podía leerlo, re-empaquetado con
 * `openssl pkcs12 -legacy` (para leerlo) seguido de un export normal (que ya
 * usa cifrado moderno) sí.
 *
 * `normalizarPfx()` hace ese mismo re-empaquetado, pero dentro de PHP: si
 * `openssl_pkcs12_read()` ya puede leer el archivo tal cual (certificados
 * exportados con herramientas modernas), lo usa directo; si no, delega el
 * re-empaquetado al binario `openssl` del sistema vía `proc_open`. La
 * contraseña viaja por variable de entorno del proceso hijo
 * (`-passin env:...`), nunca en el argv (visible en `ps`). `openssl pkcs12
 * -export` necesita leer el certificado y la llave en dos pasadas —no
 * acepta una tubería, que no se puede releer—, así que el PEM intermedio
 * (certificado + llave SIN cifrar) pasa brevemente por un archivo temporal
 * con permisos 600, borrado en cuanto termina incluso si algo falla. Si el
 * servidor no tiene el binario `openssl` disponible, falla con un mensaje
 * accionable en vez de un error críptico de OpenSSL.
 */
final class FirmanteDigital
{
    /**
     * Firma `$xmlSinFirmar` con el certificado `$pfxContenido`. Lanza
     * DomainException con un mensaje accionable si la contraseña es
     * incorrecta o el archivo no es un PKCS#12 válido.
     */
    public function firmar(string $xmlSinFirmar, string $pfxContenido, string $password): string
    {
        $pem = $this->exportarPem($this->normalizarPfx($pfxContenido, $password), $password);

        $firmante = new SignedXml;
        $firmante->setCertificate($pem);

        return $firmante->signXml($xmlSinFirmar);
    }

    /**
     * Certificado + llave privada en PEM, para alimentar a SignedXml (que
     * espera justamente ese formato, no el .pfx crudo).
     */
    private function exportarPem(string $pfxContenido, string $password): string
    {
        try {
            $x509 = new X509Certificate($pfxContenido, $password);
        } catch (Throwable $e) {
            throw new DomainException('No se pudo leer el certificado: contraseña incorrecta o archivo dañado.', previous: $e);
        }

        return $x509->export(X509ContentType::PEM);
    }

    /**
     * Devuelve un .pfx que `openssl_pkcs12_read()` (y por tanto X509Certificate)
     * pueda leer, re-empaquetando con el `openssl` del sistema si hace falta.
     */
    public function normalizarPfx(string $pfxContenido, string $password): string
    {
        if (openssl_pkcs12_read($pfxContenido, $certs, $password)) {
            return $pfxContenido;
        }

        return $this->reempaquetarConOpenssl($pfxContenido, $password);
    }

    /**
     * Si el re-empaquetado falla porque el servidor no puede correr openssl
     * en absoluto, lanza con instrucciones para arreglarlo (eso sí hay que
     * decírselo al admin). Si falla por cualquier otra razón (contraseña
     * incorrecta, archivo corrupto, cualquier cosa que no sea "este servidor
     * no tiene cómo intentarlo"), devuelve el .pfx original sin tocar: quien
     * llama vuelve a intentar leerlo tal cual y produce el mismo mensaje de
     * "contraseña incorrecta o archivo dañado" que un .pfx que nunca
     * necesitó re-empaquetarse, en vez de un stderr crudo de openssl.
     */
    private function reempaquetarConOpenssl(string $pfxContenido, string $password): string
    {
        if (! function_exists('proc_open')) {
            throw new DomainException(
                'El certificado usa un cifrado que este servidor no puede leer, y no tiene el comando openssl disponible '.
                'para re-empaquetarlo. Re-empaqueta el .pfx manualmente (openssl pkcs12 -in original.pfx -legacy -passin pass:TU_CLAVE -nodes -out tmp.pem && '.
                'openssl pkcs12 -export -in tmp.pem -passout pass:TU_CLAVE -out nuevo.pfx) y sube nuevo.pfx.'
            );
        }

        $pemTemporal = $this->archivoTemporal($pfxContenido === '' ? ' ' : $pfxContenido);

        try {
            $pem = $this->ejecutar(
                ['openssl', 'pkcs12', '-in', $pemTemporal, '-legacy', '-nodes', '-passin', 'env:UMAX_PFX_PASS'],
                $password,
            );

            if ($pem === null) {
                return $pfxContenido;
            }

            file_put_contents($pemTemporal, $pem);

            $nuevoPfx = $this->ejecutar(
                ['openssl', 'pkcs12', '-export', '-in', $pemTemporal, '-passout', 'env:UMAX_PFX_PASS', '-name', 'umax'],
                $password,
            );
        } finally {
            // La llave privada sin cifrar pasó por acá: se borra siempre,
            // incluso si algo de lo anterior falló.
            @unlink($pemTemporal);
        }

        // $nuevoPfx es null, o un .pfx que openssl generó pero con otra
        // contraseña/datos corruptos: en ambos casos se deja pasar el
        // original para que exportarPem() lo intente leer tal cual y dé el
        // mismo mensaje de "contraseña incorrecta o archivo dañado" que
        // cualquier otro .pfx ilegible, en vez de un stderr crudo de openssl.
        if ($nuevoPfx === null || ! openssl_pkcs12_read($nuevoPfx, $certs, $password)) {
            return $pfxContenido;
        }

        return $nuevoPfx;
    }

    /**
     * Archivo temporal con permisos 600 (solo el propio proceso puede
     * leerlo), para el contenido intermedio sin cifrar del re-empaquetado.
     */
    private function archivoTemporal(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'umax_sunat_');
        chmod($ruta, 0600);
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    /**
     * Corre un comando de openssl y devuelve stdout, o null si el proceso
     * corrió pero no produjo un resultado utilizable (contraseña incorrecta,
     * entrada que no es un PKCS#12, etc. — un fallo de la OPERACIÓN, no del
     * servidor).
     *
     * @param  list<string>  $comando
     */
    private function ejecutar(array $comando, string $password): ?string
    {
        $proceso = @proc_open(
            $comando,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuberias,
            null,
            ['UMAX_PFX_PASS' => $password],
        );

        if (! is_resource($proceso)) {
            return null;
        }

        fclose($tuberias[0]);

        $salida = stream_get_contents($tuberias[1]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);

        $codigo = proc_close($proceso);

        return ($codigo === 0 && $salida !== '' && $salida !== false) ? $salida : null;
    }
}
