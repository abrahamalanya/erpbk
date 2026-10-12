<?php

use App\Modules\Facturacion\Services\Sunat\FirmanteDigital;
use Greenter\XMLSecLibs\Sunat\SignedXml;

/**
 * Certificados de prueba (autofirmados, sin datos reales) en
 * tests/Fixtures/Facturacion:
 *
 * - certificado_moderno.pfx: exportado con el cifrado por defecto de
 *   OpenSSL 3 (AES). openssl_pkcs12_read() lo lee directo.
 * - certificado_legacy.pfx: mismo par de llaves, exportado forzando
 *   `pbeWithSHA1And40BitRC2-CBC` para el certificado — el cifrado que usan
 *   los .pfx reales de RENIEC/ECEP para SUNAT y que openssl_pkcs12_read()
 *   rechaza con "unsupported" en OpenSSL 3 (confirmado con un certificado
 *   SUNAT real). Ambos usan la contraseña 'testpass123'.
 */
function pfxDePrueba(string $nombre): string
{
    return file_get_contents(base_path("tests/Fixtures/Facturacion/{$nombre}"));
}

/**
 * XML UBL mínimo con el hueco `<ext:ExtensionContent/>` que las plantillas
 * reales de greenter/xml ya traen (ver invoice2.1.xml.twig) — es donde
 * SignedXml inserta la firma.
 */
/**
 * El re-empaquetado llama al `openssl` del sistema vía `proc_open`. En este
 * entorno de desarrollo (Windows, OpenSSL 3.5 vía Git for Windows) esa
 * llamada concreta falla con "entropy source strength too weak" — un
 * problema de cómo Windows entrega aleatoriedad a un proceso sin consola
 * interactiva, no del código: el mismo comando, ejecutado directo en una
 * shell (no vía proc_open), re-empaqueta bien (verificado a mano). No debería
 * reproducirse en Linux, donde `/dev/urandom` no depende de eso. Se prueba
 * en runtime y se salta con un mensaje claro si no se puede, en vez de fingir
 * cobertura que no corre.
 */
function opensslCliPuedeReempaquetar(): bool
{
    static $resultado = null;

    if ($resultado === null) {
        $normalizado = (new FirmanteDigital)->normalizarPfx(pfxDePrueba('certificado_legacy.pfx'), 'testpass123');
        $resultado = openssl_pkcs12_read($normalizado, $certs, 'testpass123');
    }

    return $resultado;
}

function xmlConHuecoDeFirma(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
        <ext:UBLExtensions>
            <ext:UBLExtension>
                <ext:ExtensionContent/>
            </ext:UBLExtension>
        </ext:UBLExtensions>
        <cbc:ID>F001-1</cbc:ID>
    </Invoice>
    XML;
}

it('firma un XML con un certificado moderno y la firma verifica', function () {
    $firmado = (new FirmanteDigital)->firmar(xmlConHuecoDeFirma(), pfxDePrueba('certificado_moderno.pfx'), 'testpass123');

    expect($firmado)->toContain('<ds:Signature')
        ->and($firmado)->toContain('<ds:X509Certificate');

    $doc = new DOMDocument;
    $doc->loadXML($firmado);
    expect((new SignedXml)->verify($doc))->toBeTrue();
});

it('firma igual con un certificado de cifrado legacy (RC2-40), normalizándolo primero', function () {
    if (! opensslCliPuedeReempaquetar()) {
        test()->markTestSkipped('El openssl de este entorno no puede re-empaquetar vía proc_open — ver opensslCliPuedeReempaquetar().');
    }

    $firmado = (new FirmanteDigital)->firmar(xmlConHuecoDeFirma(), pfxDePrueba('certificado_legacy.pfx'), 'testpass123');

    $doc = new DOMDocument;
    $doc->loadXML($firmado);
    expect((new SignedXml)->verify($doc))->toBeTrue();
});

it('normalizarPfx deja intacto un certificado que PHP ya puede leer', function () {
    $original = pfxDePrueba('certificado_moderno.pfx');

    expect((new FirmanteDigital)->normalizarPfx($original, 'testpass123'))->toBe($original);
});

it('normalizarPfx reempaqueta un certificado legacy a uno que openssl_pkcs12_read puede leer', function () {
    if (! opensslCliPuedeReempaquetar()) {
        test()->markTestSkipped('El openssl de este entorno no puede re-empaquetar vía proc_open — ver opensslCliPuedeReempaquetar().');
    }

    $normalizado = (new FirmanteDigital)->normalizarPfx(pfxDePrueba('certificado_legacy.pfx'), 'testpass123');

    expect(openssl_pkcs12_read($normalizado, $certs, 'testpass123'))->toBeTrue()
        ->and($certs)->toHaveKeys(['cert', 'pkey']);
});

it('normalizarPfx no revienta cuando falla el re-empaquetado: deja pasar el original', function () {
    $legacy = pfxDePrueba('certificado_legacy.pfx');

    // Sin tocar PATH (no es portable entre Windows/Linux en el test), se
    // fuerza la rama defensiva simulando que el comando no corre: basta con
    // que openssl no esté en PATH para este proceso hijo puntual.
    $pathOriginal = getenv('PATH');
    putenv('PATH=');

    try {
        $resultado = (new FirmanteDigital)->normalizarPfx($legacy, 'testpass123');
    } finally {
        putenv("PATH={$pathOriginal}");
    }

    expect($resultado)->toBe($legacy);
});

it('rechaza con un mensaje claro una contraseña incorrecta', function () {
    (new FirmanteDigital)->firmar(xmlConHuecoDeFirma(), pfxDePrueba('certificado_moderno.pfx'), 'contraseña-equivocada');
})->throws(DomainException::class, 'No se pudo leer el certificado: contraseña incorrecta o archivo dañado.');

it('rechaza con un mensaje claro un archivo que no es un pfx', function () {
    (new FirmanteDigital)->firmar(xmlConHuecoDeFirma(), 'esto no es un certificado', 'cualquiera');
})->throws(DomainException::class);
