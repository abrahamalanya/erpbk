<?php

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
});

it('devuelve null cuando la empresa no tiene configuración', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/configuracion-facturacion')
        ->assertSuccessful()
        ->assertJsonPath('data', null);
});

it('devuelve la configuración existente de la empresa', function () {
    ConfiguracionFacturacion::factory()->deEmpresa($this->empresa)->activa()->create([
        'modo' => 'produccion',
        'igv_porcentaje' => '18.00',
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/configuracion-facturacion')
        ->assertSuccessful()
        ->assertJsonPath('data.modo', 'produccion')
        ->assertJsonPath('data.igv_porcentaje', '18.00');
});

it('crea la configuración al actualizar por primera vez', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->putJson('/api/configuracion-facturacion', [
        'modo' => 'beta',
        'igv_porcentaje' => '18.00',
        'moneda' => 'PEN',
        'interes_incluye_igv' => true,
        'serie_factura_defecto' => 'F001',
        'serie_boleta_defecto' => 'B001',
        'activo' => true,
    ])->assertSuccessful()
        ->assertJsonPath('data.modo', 'beta')
        ->assertJsonPath('data.igv_porcentaje', '18.00');

    expect(ConfiguracionFacturacion::query()->where('empresa_id', $this->empresa->id)->count())->toBe(1);
});

it('actualiza la configuración existente sin duplicarla', function () {
    ConfiguracionFacturacion::factory()->deEmpresa($this->empresa)->activa()->create([
        'igv_porcentaje' => '18.00',
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $this->putJson('/api/configuracion-facturacion', [
        'modo' => 'produccion',
        'igv_porcentaje' => '17.00',
    ])->assertSuccessful()
        ->assertJsonPath('data.modo', 'produccion')
        ->assertJsonPath('data.igv_porcentaje', '17.00');

    expect(ConfiguracionFacturacion::query()->where('empresa_id', $this->empresa->id)->count())->toBe(1);
});

it('valida que el modo sea beta o produccion', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->putJson('/api/configuracion-facturacion', ['modo' => 'otro'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('modo');
});

it('valida que la moneda sea PEN o USD', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->putJson('/api/configuracion-facturacion', ['moneda' => 'EUR'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('moneda');
});

it('no deja editar la configuración a un rol sin el permiso', function () {
    $secretaria = User::factory()->forEmpresa($this->empresa)->create();
    $secretaria->assignRole('secretaria');
    Sanctum::actingAs($secretaria, ['*']);

    $this->putJson('/api/configuracion-facturacion', ['modo' => 'produccion'])
        ->assertForbidden();
});

it('registra una serie', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/facturacion/series', [
        'tipo_documento' => '03',
        'serie' => 'B001',
        'descripcion' => 'Boleta de Venta Electrónica',
    ])->assertCreated()
        ->assertJsonPath('data.serie', 'B001')
        ->assertJsonPath('data.tipo_documento', '03');

    expect(SerieComprobante::query()->where('serie', 'B001')->count())->toBe(1);
});

it('normaliza la serie en mayúsculas', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/facturacion/series', [
        'tipo_documento' => '03',
        'serie' => 'b001',
    ])->assertCreated()
        ->assertJsonPath('data.serie', 'B001');
});

it('rechaza una serie duplicada en la misma empresa', function () {
    SerieComprobante::factory()->deEmpresa($this->empresa)->boleta('B001')->create();

    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/facturacion/series', [
        'tipo_documento' => '03',
        'serie' => 'B001',
    ])->assertUnprocessable();
});

it('lista las series de la empresa', function () {
    SerieComprobante::factory()->deEmpresa($this->empresa)->boleta('B001')->create();
    SerieComprobante::factory()->deEmpresa($this->empresa)->factura('F001')->create();

    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/facturacion/series')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

it('inactiva una serie en vez de eliminarla cuando ya emitió', function () {
    $serie = SerieComprobante::factory()->deEmpresa($this->empresa)->boleta('B001')->create();
    // Simular que ya emitió un comprobante.
    Comprobante::factory()->create([
        'empresa_id' => $this->empresa->id,
        'series_comprobante_id' => $serie->id,
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $this->deleteJson("/api/facturacion/series/{$serie->id}")
        ->assertUnprocessable();

    // Pero sí se puede inactivar.
    $this->putJson("/api/facturacion/series/{$serie->id}", ['estado' => false])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', false);
});
