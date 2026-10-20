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
 * Construye un ZIP real (no un mock) con la estructura mínima de un APK, para
 * que ApkInspectorService lo inspeccione de verdad.
 */
function apkDePrueba(bool $esApk = true, bool $firmado = true): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'apk_test_');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);

    if ($esApk) {
        $zip->addFromString('AndroidManifest.xml', 'manifest-binario-de-prueba');
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

it('impide a quien no es sistemas administrar las versiones de APK', function () {
    Sanctum::actingAs($this->asesor);

    $this->getJson('/api/actualizaciones-apk')->assertForbidden();
    $this->postJson('/api/actualizaciones-apk', [
        'apk' => apkDePrueba(),
        'version' => '1.0.0',
        'build_number' => 1,
        'application_id' => APPLICATION_ID,
    ])->assertForbidden();

    $version = ApkVersion::query()->create([
        'application_id' => APPLICATION_ID, 'version' => '1.0.0', 'build_number' => 1,
        'file_path' => 'apk/x', 'file_name' => 'a.apk', 'file_size' => 1, 'sha256' => str_repeat('a', 64),
    ]);
    $this->postJson("/api/actualizaciones-apk/{$version->id}/publicar")->assertForbidden();
    $this->deleteJson("/api/actualizaciones-apk/{$version->id}")->assertForbidden();
    $this->getJson("/api/actualizaciones-apk/{$version->id}/descargar")->assertForbidden();
});

it('sube una versión válida y la guarda en el disco privado con su hash', function () {
    Sanctum::actingAs($this->sistemas);

    $archivo = apkDePrueba();
    $contenidoEsperado = file_get_contents($archivo->getRealPath());

    $this->postJson('/api/actualizaciones-apk', [
        'apk' => $archivo,
        'version' => '1.4.0',
        'build_number' => 20,
        'application_id' => APPLICATION_ID,
        'descripcion' => 'Primera subida',
    ])->assertCreated()->assertJsonPath('data.version', '1.4.0')->assertJsonPath('data.build_number', 20);

    $version = ApkVersion::query()->where('build_number', 20)->firstOrFail();
    expect($version->sha256)->toBe(hash('sha256', $contenidoEsperado))
        ->and($version->publicada)->toBeFalse();
    Storage::disk('privado')->assertExists($version->file_path);
});

it('rechaza un applicationId distinto al configurado', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', [
        'apk' => apkDePrueba(),
        'version' => '1.0.0',
        'build_number' => 1,
        'application_id' => 'com.otra.app',
    ])->assertUnprocessable();
});

it('rechaza un número de compilación menor o igual al ya registrado', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', [
        'apk' => apkDePrueba(), 'version' => '1.0.0', 'build_number' => 20, 'application_id' => APPLICATION_ID,
    ])->assertCreated();

    $this->postJson('/api/actualizaciones-apk', [
        'apk' => apkDePrueba(), 'version' => '1.0.1', 'build_number' => 20, 'application_id' => APPLICATION_ID,
    ])->assertUnprocessable();
});

it('rechaza un archivo sin estructura de APK', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', [
        'apk' => apkDePrueba(esApk: false), 'version' => '1.0.0', 'build_number' => 1, 'application_id' => APPLICATION_ID,
    ])->assertUnprocessable();
});

it('rechaza un APK sin firma', function () {
    Sanctum::actingAs($this->sistemas);

    $this->postJson('/api/actualizaciones-apk', [
        'apk' => apkDePrueba(firmado: false), 'version' => '1.0.0', 'build_number' => 1, 'application_id' => APPLICATION_ID,
    ])->assertUnprocessable();
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
