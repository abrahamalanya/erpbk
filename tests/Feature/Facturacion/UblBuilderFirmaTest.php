<?php

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Facturacion\Services\Sunat\UblBuilder;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Prueba que el comprobante que ya se genera y persiste (escenarioEmision(),
 * de EmitirComprobanteTest) se pueda convertir en el XML firmado que de
 * verdad se manda a SUNAT — el paso que antes no existía (ver
 * FirmanteDigital).
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('privado');
});

it('arma y firma el XML de un comprobante ya emitido', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    Storage::disk('privado')->put('sunat/empresa.pfx', file_get_contents(base_path('tests/Fixtures/Facturacion/certificado_moderno.pfx')));
    CredencialSunat::factory()->deEmpresa($empresa)->create([
        'certificado_path' => 'sunat/empresa.pfx',
        'certificado_password' => 'testpass123',
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();
    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->with('detalles')->firstOrFail();

    $firmado = app(UblBuilder::class)->build($comprobante);

    expect($firmado)->toContain('<ds:Signature')
        ->and($firmado)->toContain('<cbc:ID>'.$comprobante->numeroFormateado().'</cbc:ID>')
        ->and($firmado)->toContain('<cbc:UBLVersionID>2.1</cbc:UBLVersionID>')
        ->and($firmado)->toContain('<cbc:CustomizationID>2.0</cbc:CustomizationID>');

    $doc = new DOMDocument;
    $doc->loadXML($firmado);
    expect((new SignedXml)->verify($doc))->toBeTrue();
});

it('arma los montos UBL correctos: base imponible, IGV y total a pagar', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    Storage::disk('privado')->put('sunat/empresa.pfx', file_get_contents(base_path('tests/Fixtures/Facturacion/certificado_moderno.pfx')));
    CredencialSunat::factory()->deEmpresa($empresa)->create([
        'certificado_path' => 'sunat/empresa.pfx',
        'certificado_password' => 'testpass123',
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();
    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->with('detalles')->firstOrFail();

    $firmado = app(UblBuilder::class)->build($comprobante);
    $doc = new DOMDocument;
    $doc->loadXML($firmado);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

    $texto = fn (string $expr): string => $xp->evaluate("string({$expr})");

    // 84.75 base + 15.26 IGV = 100.01 total — los mismos valores que
    // BaseImponibleTest ya prueba para este crédito.
    expect($texto('/*/cac:LegalMonetaryTotal/cbc:PayableAmount'))->toBe('100.01')
        ->and($texto('/*/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'))->toBe('84.75')
        ->and($texto('/*/cac:TaxTotal/cbc:TaxAmount'))->toBe('15.26')
        ->and($texto('/*/cac:AccountingSupplierParty//cbc:RegistrationName'))->toBe($comprobante->emisor_razon_social)
        ->and($texto('/*/cac:AccountingCustomerParty//cac:PartyIdentification/cbc:ID'))->toBe($comprobante->receptor_numero_documento)
        ->and($texto('/*/cac:InvoiceLine/cac:Item/cbc:Description'))->toContain('Interés');
});

it('rechaza armar el XML si la empresa no tiene credenciales SUNAT completas', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();
    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->with('detalles')->firstOrFail();

    app(UblBuilder::class)->build($comprobante);
})->throws(DomainException::class, 'Credenciales SUNAT incompletas: falta usuario SOL, clave SOL o certificado .pfx.');

it('arma el ZIP de archivo con el XML firmado adentro', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    Storage::disk('privado')->put('sunat/empresa.pfx', file_get_contents(base_path('tests/Fixtures/Facturacion/certificado_moderno.pfx')));
    CredencialSunat::factory()->deEmpresa($empresa)->create([
        'certificado_path' => 'sunat/empresa.pfx',
        'certificado_password' => 'testpass123',
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();
    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->with('detalles')->firstOrFail();

    $zip = app(UblBuilder::class)->buildZip($comprobante);

    $archivo = sys_get_temp_dir().'/'.uniqid('ublzip_').'.zip';
    file_put_contents($archivo, $zip);
    $apertura = new ZipArchive;
    expect($apertura->open($archivo))->toBeTrue()
        ->and($apertura->numFiles)->toBe(1)
        ->and($apertura->getNameIndex(0))->toEndWith('.xml');
    $apertura->close();
    unlink($archivo);
});
