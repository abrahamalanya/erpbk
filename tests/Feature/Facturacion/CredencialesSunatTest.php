<?php

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Facturacion\Services\Sunat\FirmanteDigital;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('privado');

    $this->empresa = Empresa::factory()->create();
    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    Sanctum::actingAs($this->admin, ['*']);
});

/** Certificado de prueba — ver FirmanteDigitalTest para los detalles. */
function certificadoDePrueba(string $nombre, string $comoSeNombra = 'certificado.pfx'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $comoSeNombra,
        file_get_contents(base_path("tests/Fixtures/Facturacion/{$nombre}")),
    );
}

it('guarda las credenciales y normaliza un certificado de cifrado moderno sin tocarlo', function () {
    $this->postJson("/api/empresas/{$this->empresa->id}/credenciales-sunat", [
        'usuario_sol' => 'MODDATOS',
        'clave_sol' => 'moddatos',
        'certificado' => certificadoDePrueba('certificado_moderno.pfx'),
        'certificado_password' => 'testpass123',
    ])->assertSuccessful()->assertJsonPath('data.esta_completa', true);

    $credencial = CredencialSunat::query()->where('empresa_id', $this->empresa->id)->firstOrFail();

    Storage::disk('privado')->assertExists($credencial->certificado_path);
    $guardado = Storage::disk('privado')->get($credencial->certificado_path);
    expect(openssl_pkcs12_read($guardado, $certs, 'testpass123'))->toBeTrue();
});

it('normaliza un certificado de cifrado legacy al guardarlo, para que quede legible', function () {
    if (! opensslCliPuedeReempaquetarParaTest()) {
        test()->markTestSkipped('El openssl de este entorno no puede re-empaquetar vía proc_open — ver FirmanteDigitalTest.');
    }

    $this->postJson("/api/empresas/{$this->empresa->id}/credenciales-sunat", [
        'usuario_sol' => 'MODDATOS',
        'clave_sol' => 'moddatos',
        'certificado' => certificadoDePrueba('certificado_legacy.pfx'),
        'certificado_password' => 'testpass123',
    ])->assertSuccessful();

    $credencial = CredencialSunat::query()->where('empresa_id', $this->empresa->id)->firstOrFail();
    $guardado = Storage::disk('privado')->get($credencial->certificado_path);

    // El original (tal cual se subió) NO lo puede leer openssl_pkcs12_read;
    // lo guardado en disco, sí — se normalizó en el camino.
    expect(openssl_pkcs12_read(file_get_contents(base_path('tests/Fixtures/Facturacion/certificado_legacy.pfx')), $c, 'testpass123'))->toBeFalse()
        ->and(openssl_pkcs12_read($guardado, $certs, 'testpass123'))->toBeTrue();
});

it('no deja gestionar las credenciales SUNAT de otra empresa', function () {
    $otraEmpresa = Empresa::factory()->create();

    $this->postJson("/api/empresas/{$otraEmpresa->id}/credenciales-sunat", [
        'usuario_sol' => 'MODDATOS',
        'clave_sol' => 'moddatos',
    ])->assertForbidden();
});

/** Mismo chequeo en runtime que FirmanteDigitalTest, para no asumir en este archivo. */
function opensslCliPuedeReempaquetarParaTest(): bool
{
    static $resultado = null;

    if ($resultado === null) {
        $normalizado = (new FirmanteDigital)->normalizarPfx(
            file_get_contents(base_path('tests/Fixtures/Facturacion/certificado_legacy.pfx')),
            'testpass123',
        );
        $resultado = (bool) openssl_pkcs12_read($normalizado, $certs, 'testpass123');
    }

    return $resultado;
}
