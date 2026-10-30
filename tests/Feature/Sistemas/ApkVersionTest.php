<?php

use App\Modules\Sistemas\Models\ApkVersion;
use App\Modules\Usuario\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

const APPLICATION_ID = 'com.credimasperu.umax';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('privado');

    $this->sistemas = User::factory()->create();
    $this->sistemas->assignRole('sistemas');

    $this->asesor = User::factory()->create();
    $this->asesor->assignRole('asesor');
});

/**
 * Codifica un AndroidManifest.xml binario (AXML) mínimo pero real: un pool de
 * cadenas UTF-16 y un elemento raíz <manifest> con los atributos package,
 * versionCode y versionName — exactamente lo que ApkInspectorService decodifica
 * de un APK real. Permite probar la extracción real sin depender de un APK de
 * verdad a mano.
 */
function axmlManifiesto(string $package, int $versionCode, string $versionName): string
{
    $cadenaU16 = function (string $s): string {
        return pack('v', mb_strlen($s, 'UTF-8')).mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')."\x00\x00";
    };

    $cadenas = ['manifest', 'package', $package, 'versionCode', 'versionName', $versionName];
    $datos = '';
    $offsets = [];
    foreach ($cadenas as $s) {
        $offsets[] = strlen($datos);
        $datos .= $cadenaU16($s);
    }
    $headerSizePool = 28;
    $offsetsBytes = implode('', array_map(fn ($o) => pack('V', $o), $offsets));
    $stringsStart = $headerSizePool + strlen($offsetsBytes);
    $cuerpoPool = $offsetsBytes.$datos;
    while ((strlen($cuerpoPool) + $headerSizePool) % 4 !== 0) {
        $cuerpoPool .= "\x00";
    }
    $tamanoPool = $headerSizePool + strlen($cuerpoPool);
    $pool = pack('v', 0x0001).pack('v', $headerSizePool).pack('V', $tamanoPool)
        .pack('V', count($cadenas)).pack('V', 0).pack('V', 0).pack('V', $stringsStart).pack('V', 0)
        .$cuerpoPool;

    $attr = fn (int $ns, int $nombreIdx, int $rawValueIdx, int $tipo, int $dato) => pack('V', $ns).pack('V', $nombreIdx).pack('V', $rawValueIdx)
        .pack('v', 8).pack('C', 0).pack('C', $tipo).pack('V', $dato);

    $attributos = $attr(0xFFFFFFFF, 1, 2, 0x03, 2) // package="..."
        .$attr(0xFFFFFFFF, 3, 0xFFFFFFFF, 0x10, $versionCode) // versionCode=int
        .$attr(0xFFFFFFFF, 4, 5, 0x03, 5); // versionName="..."

    $attrExt = pack('V', 0xFFFFFFFF).pack('V', 0) // ns, name (0 = "manifest")
        .pack('v', 20).pack('v', 20).pack('v', 3).pack('v', 0).pack('v', 0).pack('v', 0);
    $nodo = pack('V', 0).pack('V', 0xFFFFFFFF); // lineNumber, comment
    $cuerpoElemento = $nodo.$attrExt.$attributos;
    $elemento = pack('v', 0x0102).pack('v', 16).pack('V', 8 + strlen($cuerpoElemento)).$cuerpoElemento;

    $cuerpo = $pool.$elemento;

    return pack('v', 0x0003).pack('v', 8).pack('V', 8 + strlen($cuerpo)).$cuerpo;
}

/**
 * Construye un ZIP real (no un mock) con la estructura mínima de un APK, para
 * que ApkInspectorService lo inspeccione de verdad. El AndroidManifest.xml es
 * un AXML real (ver axmlManifiesto) salvo que $manifiestoValido sea false.
 */
function apkDePrueba(
    bool $esApk = true,
    bool $firmado = true,
    bool $manifiestoValido = true,
    string $package = APPLICATION_ID,
    int $versionCode = 20,
    string $versionName = '1.4.0',
): UploadedFile {
    $tmp = tempnam(sys_get_temp_dir(), 'apk_test_');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);

    if ($esApk) {
        $manifiesto = $manifiestoValido ? axmlManifiesto($package, $versionCode, $versionName) : 'manifest-binario-invalido';
        $zip->addFromString('AndroidManifest.xml', $manifiesto);
        $zip->addFromString('classes.dex', 'dex-de-prueba');
    } else {
        $zip->addFromString('readme.txt', 'esto no es un apk');
    }

    if ($firmado) {
        $zip->addFromString('META-INF/CERT.RSA', 'bytes-de-certificado-de-prueba');
    }

    $zip->close();
    $contenido = file_get_contents($tmp);
    unlink($tmp);

    return UploadedFile::fake()->createWithContent('app.apk', $contenido);
}

/** Escribe un bloque length-prefixed (uint32 LE): 4 bytes de longitud + $bytes. */
function lp(string $bytes): string
{
    return pack('V', strlen($bytes)).$bytes;
}

/**
 * Construye un ZIP con un directorio central inflado (muchas entradas, para
 * que supere los 64KB — el caso real que hace fallar la ventana fija vieja)
 * y le inserta, justo antes del directorio central, un APK Signing Block v2
 * real y completo (con un certificado X.509 de prueba generado con openssl),
 * parchando el offset de EOCD para que apunte al directorio central ya
 * desplazado. Devuelve [UploadedFile, huella SHA-256 esperada del certificado].
 *
 * @return array{0: UploadedFile, 1: string}
 */
function apkFirmadoV2DePrueba(int $versionCode = 1, string $versionName = '1.0.0'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'apk_v2_test_');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('AndroidManifest.xml', axmlManifiesto(APPLICATION_ID, $versionCode, $versionName));
    $zip->addFromString('classes.dex', 'dex-de-prueba');

    // Infla el directorio central bastante más allá de 64KB — exactamente lo
    // que un APK real de React Native/Expo tiene de sobra (recursos, .so por
    // ABI, etc.) y lo que hacía fallar la búsqueda con ventana fija vieja.
    for ($i = 0; $i < 1500; $i++) {
        $zip->addFromString("assets/recurso_de_prueba_{$i}.bin", '');
    }

    $zip->close();
    $crudo = file_get_contents($tmp);
    unlink($tmp);

    // El ZIP recién cerrado por ZipArchive no tiene comentario de archivo,
    // así que el EOCD mide exactamente 22 bytes al final.
    $eocdPos = strlen($crudo) - 22;
    expect(substr($crudo, $eocdPos, 4))->toBe("\x50\x4b\x05\x06");
    $cdOffsetOriginal = unpack('V', substr($crudo, $eocdPos + 16, 4))[1];

    $seccionLocal = substr($crudo, 0, $cdOffsetOriginal);
    $seccionCdYEocd = substr($crudo, $cdOffsetOriginal);

    // Certificado X.509 de prueba real (autofirmado), para poder comparar su
    // huella SHA-256 contra la que debería extraer ApkInspectorService.
    // `config`: en Windows, openssl_csr_new()/openssl_csr_sign() no resuelven
    // un openssl.cnf por su cuenta sin OPENSSL_CONF — se le pasa explícito.
    $opensslConfig = [];
    $cnfCandidato = getenv('OPENSSL_CONF') ?: (getenv('USERPROFILE').'\\.config\\herd\\bin\\php84\\extras\\ssl\\openssl.cnf');
    if (file_exists($cnfCandidato)) {
        $opensslConfig = ['config' => $cnfCandidato];
    }

    $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $opensslConfig);
    $csr = openssl_csr_new(['commonName' => 'UMAX Test Signer'], $clave, $opensslConfig);
    $certificado = openssl_csr_sign($csr, null, $clave, 365, $opensslConfig);
    openssl_x509_export($certificado, $pem);
    $huellaEsperada = openssl_x509_fingerprint($pem, 'sha256');
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));

    $digestsLp = lp('');
    $certificadosLp = lp(lp($der));
    $signedDataLp = lp($digestsLp.$certificadosLp);
    $signerLp = lp($signedDataLp.lp('').lp(''));
    $valorV2 = lp($signerLp);

    $idValuePair = pack('P', 4 + strlen($valorV2)).pack('V', 0x7109871A).$valorV2;
    $tamanoBloque = strlen($idValuePair) + 24;
    $bloqueFirma = pack('P', $tamanoBloque).$idValuePair.pack('P', $tamanoBloque).'APK Sig Block 42';

    $nuevoCdOffset = $cdOffsetOriginal + strlen($bloqueFirma);
    $nuevoEocdPos = strlen($seccionCdYEocd) - 22;
    $seccionCdYEocd = substr_replace($seccionCdYEocd, pack('V', $nuevoCdOffset), $nuevoEocdPos + 16, 4);

    $apkFinal = $seccionLocal.$bloqueFirma.$seccionCdYEocd;

    return [UploadedFile::fake()->createWithContent('app.apk', $apkFinal), $huellaEsperada];
}

it('impide a quien no es sistemas administrar las versiones de APK', function () {
    Sanctum::actingAs($this->asesor);

    $this->getJson('/api/actualizaciones-apk')->assertForbidden();
    $this->postJson('/api/actualizaciones-apk', ['apk' => apkDePrueba()])->assertForbidden();

    $version = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.0.0', 'build_number' => 1,
        'file_path' => 'apk/x', 'file_name' => 'a.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64),
    ]);
    $this->postJson("/api/actualizaciones-apk/{$version->id}/publicar")->assertForbidden();
    $this->deleteJson("/api/actualizaciones-apk/{$version->id}")->assertForbidden();
    $this->getJson("/api/actualizaciones-apk/{$version->id}/descargar")->assertForbidden();
});

it('sube una versión válida y guarda en el disco privado el version/build_number/applicationId reales leídos del APK', function () {
    Sanctum::actingAs($this->sistemas);

    $archivo = apkDePrueba(versionCode: 20, versionName: '1.4.0');
    $contenidoEsperado = file_get_contents($archivo->getRealPath());

    $this->postJson('/api/actualizaciones-apk', ['apk' => $archivo, 'descripcion' => 'Primera subida'])
        ->assertCreated()
        ->assertJsonPath('data.version', '1.4.0')
        ->assertJsonPath('data.build_number', 20);

    $version = ApkVersion::query()->where('build_number', 20)->firstOrFail();
    expect($version->application_id)->toBe(APPLICATION_ID)
        ->and($version->sha256)->toBe(hash('sha256', $contenidoEsperado))
        ->and($version->publicada)->toBeFalse();
    Storage::disk('privado')->assertExists($version->file_path);
});

it('rechaza un applicationId real (leído del AndroidManifest.xml) distinto al configurado', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', [
        'apk' => apkDePrueba(package: 'com.otra.app'),
    ])->assertUnprocessable();
});

it('rechaza un versionCode real menor o igual al ya registrado, aunque el archivo sea distinto', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', ['apk' => apkDePrueba(versionCode: 20, versionName: '1.0.0')])
        ->assertCreated();

    // Simula el bug real: un build local con versionCode viejo (la carpeta
    // android/ no se regeneró tras bajar app.config.ts) — el backend debe
    // rechazarlo en vez de confiar en que "debería" ser mayor.
    $this->postJson('/api/actualizaciones-apk', ['apk' => apkDePrueba(versionCode: 20, versionName: '1.0.1')])
        ->assertUnprocessable();
});

it('rechaza un archivo sin estructura de APK', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', ['apk' => apkDePrueba(esApk: false)])->assertUnprocessable();
});

it('rechaza un APK sin firma', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', ['apk' => apkDePrueba(firmado: false)])->assertUnprocessable();
});

it('rechaza un APK cuyo AndroidManifest.xml no se puede decodificar como AXML', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', ['apk' => apkDePrueba(manifiestoValido: false)])
        ->assertUnprocessable();
});

it('rechaza un APK firmado con un certificado distinto al de la versión vigente', function () {
    Sanctum::actingAs($this->sistemas);

    [$archivoV1, $huella1] = apkFirmadoV2DePrueba(versionCode: 1, versionName: '1.0.0');
    $this->postJson('/api/actualizaciones-apk', ['apk' => $archivoV1])->assertCreated();
    $v1 = ApkVersion::query()->where('build_number', 1)->firstOrFail();
    $this->postJson("/api/actualizaciones-apk/{$v1->id}/publicar")->assertSuccessful();

    [$archivoV2, $huella2] = apkFirmadoV2DePrueba(versionCode: 2, versionName: '1.0.1');
    expect($huella2)->not->toBe($huella1); // cada llamada genera un certificado autofirmado distinto

    $this->postJson('/api/actualizaciones-apk', ['apk' => $archivoV2])->assertUnprocessable();
});

it('detecta como firmado un APK real con bloque de firma v2 fuera de la ventana de 64KB, y extrae la huella del certificado', function () {
    Sanctum::actingAs($this->sistemas);

    [$archivo, $huellaEsperada] = apkFirmadoV2DePrueba(versionCode: 1, versionName: '1.0.0');

    $response = $this->postJson('/api/actualizaciones-apk', ['apk' => $archivo])->assertCreated();

    expect($response->json('data.certificado_sha256'))->toBe($huellaEsperada);

    $version = ApkVersion::query()->where('build_number', 1)->firstOrFail();
    expect($version->certificado_sha256)->toBe($huellaEsperada);
});

it('publicar despublica la versión vigente anterior', function () {
    Sanctum::actingAs($this->sistemas);

    $v1 = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.0.0', 'build_number' => 1, 'publicada' => true, 'publicada_at' => now(),
        'file_path' => 'apk/v1', 'file_name' => 'v1.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64),
    ]);
    $v2 = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.1.0', 'build_number' => 2,
        'file_path' => 'apk/v2', 'file_name' => 'v2.apk', 'file_size' => 1, 'sha256' => str_repeat('b', 64),
    ]);

    $this->postJson("/api/actualizaciones-apk/{$v2->id}/publicar")->assertSuccessful()->assertJsonPath('data.publicada', true);

    expect($v1->fresh()->publicada)->toBeFalse()
        ->and($v2->fresh()->publicada)->toBeTrue();
});

it('protege la versión vigente de ser eliminada', function () {
    Sanctum::actingAs($this->sistemas);
    Storage::disk('privado')->put('apk/vigente', 'contenido');

    $vigente = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.0.0', 'build_number' => 1, 'publicada' => true, 'publicada_at' => now(),
        'file_path' => 'apk/vigente', 'file_name' => 'v.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64),
    ]);

    $this->deleteJson("/api/actualizaciones-apk/{$vigente->id}")->assertStatus(409);
    Storage::disk('privado')->assertExists('apk/vigente');
});

it('elimina una versión no vigente y borra su archivo', function () {
    Sanctum::actingAs($this->sistemas);
    Storage::disk('privado')->put('apk/vieja', 'contenido');

    $version = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.0.0', 'build_number' => 1,
        'file_path' => 'apk/vieja', 'file_name' => 'v.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64),
    ]);

    $this->deleteJson("/api/actualizaciones-apk/{$version->id}")->assertSuccessful();
    $this->assertDatabaseMissing('apk_versions', ['id' => $version->id]);
    Storage::disk('privado')->assertMissing('apk/vieja');
});

it('cualquier usuario autenticado puede consultar la versión vigente, con una URL firmada de descarga', function () {
    Sanctum::actingAs($this->asesor);

    $this->getJson('/api/actualizaciones-apk/vigente')->assertSuccessful()->assertJsonPath('data', null);

    $vigente = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.0.0', 'build_number' => 5, 'publicada' => true, 'publicada_at' => now(),
        'file_path' => 'apk/vigente', 'file_name' => 'v.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64),
    ]);

    $respuesta = $this->getJson('/api/actualizaciones-apk/vigente')->assertSuccessful()->assertJsonPath('data.build_number', 5);
    $url = $respuesta->json('data.descargar_url');
    expect($url)->toContain('/api/apk-updates/'.$vigente->id.'/archivo')->toContain('signature=');
});

it('permite detectar y descargar la versión vigente antes de iniciar sesión sin exponer la administración', function () {
    $this->getJson('/api/actualizaciones-apk/vigente')->assertOk()->assertJsonPath('data', null);
    $vigente = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.3.7', 'build_number' => 24,
        'publicada' => true, 'publicada_at' => now(), 'file_path' => 'apk/vigente',
        'file_name' => 'umax.apk', 'file_size' => 3, 'sha256' => hash('sha256', 'apk'),
    ]);
    Storage::disk('privado')->put($vigente->file_path, 'apk');
    $respuesta = $this->getJson('/api/actualizaciones-apk/vigente')->assertOk()
        ->assertJsonPath('data.build_number', 24);
    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store');
    $this->get($respuesta->json('data.descargar_url'))->assertOk();
    $this->getJson('/api/actualizaciones-apk')->assertUnauthorized();
    $this->postJson('/api/actualizaciones-apk')->assertUnauthorized();
});

it('descarga el APK vigente desde la URL firmada sin necesitar sesión', function () {
    Storage::disk('privado')->put('apk/vigente', 'contenido-del-apk');

    $vigente = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.0.0', 'build_number' => 5, 'publicada' => true, 'publicada_at' => now(),
        'file_path' => 'apk/vigente', 'file_name' => 'v.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64),
    ]);

    $url = URL::temporarySignedRoute('apk-updates.descargar', now()->addMinutes(30), ['apkVersion' => $vigente->id]);

    $this->get($url)->assertSuccessful();
});

it('rechaza la descarga firmada de una versión que ya no es la vigente', function () {
    Storage::disk('privado')->put('apk/vieja', 'contenido');

    $version = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.0.0', 'build_number' => 1,
        'file_path' => 'apk/vieja', 'file_name' => 'v.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64),
    ]);

    $url = URL::temporarySignedRoute('apk-updates.descargar', now()->addMinutes(30), ['apkVersion' => $version->id]);

    $this->get($url)->assertNotFound();
});
