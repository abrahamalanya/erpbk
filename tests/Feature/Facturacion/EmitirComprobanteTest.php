<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Arma el escenario completo para poder emitir: empresa con datos fiscales,
 * serie registrada y un crédito cancelado con cobros.
 */
function escenarioEmision(array $cobros = [], string $estadoCredito = 'liquidado'): array
{
    $distrito = UbigeoDistrito::firstOrCreate(
        ['ubigeo_provincia_id' => UbigeoProvincia::firstOrCreate(
            ['ubigeo_departamento_id' => UbigeoDepartamento::firstOrCreate(['codigo' => '15'], ['nombre' => 'Lima'])->id, 'codigo' => '01'],
            ['nombre' => 'Lima']
        )->id, 'codigo' => '01'],
        ['nombre' => 'Lima']
    );

    $empresa = Empresa::factory()->create([
        'ruc' => '20601030013',
        'razon_social' => 'CREDIMAS PERU S.A.C.',
        'domicilio_calle' => 'Av. Javier Prado Este',
        'domicilio_numero' => '4200',
        'ubigeo_distrito_id' => $distrito->id,
        'correo_facturacion' => 'facturacion@credimasperu.com',
    ]);

    $agencia = Agencia::factory()->for($empresa)->create([
        'domicilio_calle' => 'Av. Alameda Central',
        'domicilio_numero' => '1234',
        'ubigeo_distrito_id' => $distrito->id,
        'codigo_establecimiento' => '0001',
    ]);

    ConfiguracionFacturacion::factory()->deEmpresa($empresa)->activa()->create();

    SerieComprobante::factory()->deAgencia($agencia)->boleta('B001')->create();
    SerieComprobante::factory()->deAgencia($agencia)->factura('F001')->create();

    // El cliente tiene que pertenecer a la MISMA empresa del crédito.
    // La factory de Crédito crea el cliente con su propia empresa, y al
    // autenticar con un usuario, el TenantScope lo esconde — por eso el
    // crédito aparece sin cliente en la emisión.
    $cliente = Cliente::factory()->create([
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
    ]);

    $credito = Credito::factory()->create([
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
        'cliente_id' => $cliente->id,
        'estado' => $estadoCredito,
        'monto_prestamo' => '1000.00',
        'codigo' => 'C-000123',
    ]);

    foreach ($cobros as $atributos) {
        Cobro::factory()->create(array_merge([
            'credito_id' => $credito->id,
            'cliente_id' => $credito->cliente_id,
            'empresa_id' => $empresa->id,
            'estado' => 'registrado',
            'mora' => null,
            'vuelto' => 0,
        ], $atributos));
    }

    return [$empresa, $agencia, $credito->fresh()];
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('emite un comprobante por el interés del crédito con su correlativo', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")
        ->assertCreated()
        ->assertJsonPath('data.estado', 'generado')
        ->assertJsonPath('data.serie', 'B001')
        ->assertJsonPath('data.correlativo', 1)
        ->assertJsonPath('data.numero', 'B001-00000001');

    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->firstOrFail();

    expect($comprobante->tipo_documento)->toBe(TipoComprobante::Boleta)
        ->and($comprobante->subtotal)->toBe('84.75')
        ->and($comprobante->igv)->toBe('15.26')
        ->and($comprobante->total)->toBe('100.01')
        ->and($comprobante->detalles)->toHaveCount(1)
        ->and($comprobante->detalles->first()->descripcion)->toContain('crédito C-000123');
});

it('emite una factura cuando el cliente tiene RUC', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $credito->cliente->update(['tipo_documento' => 'ruc', 'numero_documento' => '20601030013']);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'Factura Electrónica');

    expect(Comprobante::query()->where('origen_id', $credito->id)->firstOrFail()->serie)->toBe('F001');
});

it('guarda el snapshot fiscal del emisor y del receptor', function () {
    // Si mañana se corrige el RUC del cliente, el comprobante ya emitido no
    // debe cambiar: el PDF se re-renderiza desde esta fila.
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();

    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->firstOrFail();

    expect($comprobante->emisor_ruc)->toBe('20601030013')
        ->and($comprobante->emisor_razon_social)->toBe('CREDIMAS PERU S.A.C.')
        ->and($comprobante->emisor_domicilio)->toBe('Av. Javier Prado Este N.° 4200')
        ->and($comprobante->emisor_ubigeo)->toBe('150101')
        ->and($comprobante->receptor_tipo_documento)->toBe('dni')
        ->and($comprobante->receptor_numero_documento)->toBe($credito->cliente->numero_documento);
});

it('rechaza emitir un comprobante de un crédito que no está cancelado', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ], 'activo');

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")
        ->assertUnprocessable()
        ->assertJsonPath('message', "El crédito {$credito->codigo} no está cancelado (estado: activo). Solo se factura un crédito liquidado.");
});

it('rechaza emitir dos veces el mismo crédito', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")
        ->assertUnprocessable()
        ->assertJsonPath('message', "El crédito {$credito->codigo} ya tiene un comprobante emitido.");
});

it('rechaza emitir si la empresa no tiene completos sus datos fiscales', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $empresa->update(['ruc' => null, 'domicilio_calle' => null, 'ubigeo_distrito_id' => null]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La empresa no tiene completos sus datos fiscales: RUC, domicilio fiscal. Complete la configuración antes de emitir.');
});

it('rechaza emitir si no hay serie registrada para ese tipo de comprobante', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    // Solo hay serie de boleta; el cliente tiene RUC, así que pide factura.
    SerieComprobante::query()->where('tipo_documento', '01')->delete();
    $credito->cliente->update(['tipo_documento' => 'ruc', 'numero_documento' => '20601030013']);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No hay ninguna serie activa de Factura Electrónica en este punto de emisión. Debe registrar una antes de emitir.');
});

it('lista los créditos pendientes de facturar', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    // Un crédito activo no debe aparecer.
    Credito::factory()->create([
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
        'estado' => 'activo',
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/facturacion/creditos-pendientes')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.codigo'))->toBe('C-000123');
});

it('saca al crédito de la pendiente una vez emitido el comprobante', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();

    $this->getJson('/api/facturacion/creditos-pendientes')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

it('previsualiza lo que se va a facturar sin consumir correlativo', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson("/api/facturacion/creditos/{$credito->id}/previsualizar")
        ->assertSuccessful()
        ->assertJsonPath('data.serie', 'B001')
        ->assertJsonPath('data.correlativo_siguiente', 1)
        ->assertJsonPath('data.total', '100.01');

    // El correlativo no se consumió: el comprobante no existe todavía.
    expect(Comprobante::query()->count())->toBe(0);
});

it('anula un comprobante generado que aún no se envía a SUNAT', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();

    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->firstOrFail();

    $this->postJson("/api/facturacion/comprobantes/{$comprobante->id}/anular", [
        'motivo' => 'Error en los datos del cliente',
    ])->assertSuccessful()
        ->assertJsonPath('data.estado', 'anulado');

    expect($comprobante->fresh()->estado)->toBe(EstadoComprobante::Anulado);
});

it('no deja anular un comprobante que ya fue aceptado por SUNAT', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();

    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->firstOrFail();
    $comprobante->update(['estado' => EstadoComprobante::Aceptado->value]);

    $this->postJson("/api/facturacion/comprobantes/{$comprobante->id}/anular", [
        'motivo' => 'Quiero borrarlo',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Este comprobante ya fue aceptado por SUNAT: no se puede anular directamente. Debe emitir una nota de crédito o comunicar la baja.');
});

it('solo deja anular al administrador general', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();

    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->firstOrFail();

    $agenciaAdmin = User::factory()->forEmpresa($empresa)->create();
    $agenciaAdmin->assignRole('administrador_agencia');
    Sanctum::actingAs($agenciaAdmin, ['*']);

    $this->postJson("/api/facturacion/comprobantes/{$comprobante->id}/anular", [
        'motivo' => 'No debería poder',
    ])->assertForbidden();
});

it('no deja emitir a un rol sin el permiso de crear', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $secretaria = User::factory()->forEmpresa($empresa)->create();
    $secretaria->assignRole('secretaria');
    Sanctum::actingAs($secretaria, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertForbidden();
});

it('mantiene la numeración aunque la emisión se intente dos veces', function () {
    // Si el primer intento falla a mitad de transacción, el correlativo no
    // debe quedar consumido: un hueco invalida la operación ante SUNAT.
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();

    $serie = SerieComprobante::query()->where('serie', 'B001')->firstOrFail();

    // Un solo comprobante, con el correlativo 1.
    expect(Comprobante::query()->count())->toBe(1)
        ->and($serie->fresh()->ultimo_correlativo)->toBe(1);
});

it('genera el PDF del comprobante con los datos obligatorios', function () {
    [$empresa, $agencia, $credito] = escenarioEmision([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/facturacion/creditos/{$credito->id}/emitir")->assertCreated();

    $comprobante = Comprobante::query()->where('origen_id', $credito->id)->firstOrFail();

    $response = $this->get("/api/facturacion/comprobantes/{$comprobante->id}/pdf");

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('pdf');
});
