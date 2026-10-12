<?php

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\CredencialSunat;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('privado');

    $this->empresa = Empresa::factory()->create();
    Storage::disk('privado')->put('sunat/empresa.pfx', file_get_contents(base_path('tests/Fixtures/Facturacion/certificado_moderno.pfx')));
    $this->credencial = CredencialSunat::factory()->deEmpresa($this->empresa)->create([
        'certificado_path' => 'sunat/empresa.pfx',
        'certificado_password' => 'testpass123',
    ]);
});

it('valida un certificado real firmando y verificando con SHA-1', function () {
    // openssl_pkcs12_read() devuelve la llave privada como string PEM;
    // openssl_pkey_get_details() necesita el objeto que da
    // openssl_pkey_get_private(), no el string — pasarlo directo revienta
    // con "must be of type OpenSSLAsymmetricKey, string given". Nunca se
    // había corrido este comando contra un certificado real antes de hoy.
    $this->artisan('facturacion:validar-certificado', ['empresa_id' => $this->empresa->id])
        ->expectsOutputToContain('Certificado válido y firmando correctamente')
        ->assertExitCode(0);

    expect($this->credencial->fresh()->ultima_validacion_ok)->toBeTrue();
});
