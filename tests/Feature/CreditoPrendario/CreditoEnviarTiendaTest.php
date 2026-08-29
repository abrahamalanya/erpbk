<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Services\CreditoPrendarioService;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create();

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

it('lets an administrador_agencia send a vencido crédito past the período de espera to the tienda, with a sale price per bien', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)
        ->vencido(diasVencido: 20)
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", [
        'precios' => [$this->bien->id => 1499.90],
    ])->assertSuccessful();

    expect($response->json('data.estado'))->toBe('en_venta')
        ->and($this->bien->fresh()->estado)->toBe('disponible_venta')
        ->and($this->bien->fresh()->precio_venta)->toBe('1499.90');
});

it('rejects enviar-tienda when a bien has no sale price', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)
        ->vencido(diasVencido: 20)
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", ['precios' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('precios');

    expect($credito->fresh()->estado)->toBe('vencido');
});

it('shows the sale price in the public tienda listing', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)
        ->vencido(diasVencido: 20)
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", [
        'precios' => [$this->bien->id => 900],
    ])->assertSuccessful();

    $this->getJson("/api/tienda/bienes/{$this->bien->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.precio_venta', '900.00');
});

it('falls back to the valorización as sale price when the daily batch sends a crédito to the tienda', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)
        ->vencido(diasVencido: 20)
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    app(CreditoPrendarioService::class)->actualizarEstadosVencidos();

    expect($this->bien->fresh()->estado)->toBe('disponible_venta')
        ->and($this->bien->fresh()->precio_venta)->toBe($this->bien->fresh()->valorizacion);
});

it('rejects enviar-tienda when the crédito has not yet surpassed the período de espera', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)
        ->vencido(diasVencido: 5)
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", ['precios' => [$this->bien->id => 900]])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Este crédito aún no supera los 15 días de espera configurados.');

    expect($credito->fresh()->estado)->toBe('vencido');
});

it('rejects enviar-tienda when the crédito is not vencido', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", ['precios' => [$this->bien->id => 900]])
        ->assertUnprocessable();
});

it('forbids an asesor from sending a crédito to the tienda', function () {
    $credito = CreditoPrendario::factory()->paraBien($this->bien)
        ->vencido(diasVencido: 20)
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda")->assertForbidden();
});
