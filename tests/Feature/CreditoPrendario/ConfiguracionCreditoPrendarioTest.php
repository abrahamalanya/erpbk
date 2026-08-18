<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
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
});

it('allows administrador_general to set the empresa-wide default configuration', function () {
    $admin = User::factory()->forEmpresa($this->empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->putJson('/api/configuraciones-credito-prendario', [
        'tipo' => 'electro',
        'interes_default' => 10,
        'plazo_dias' => 30,
        'dias_espera_mora' => 15,
        'tasa_mora_diaria' => 1,
    ])->assertSuccessful()->assertJsonPath('data.agencia_id', null);
});

it('denies administrador_agencia from setting the empresa-wide default (no agencia_id)', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-credito-prendario', [
        'tipo' => 'electro',
        'interes_default' => 10,
        'plazo_dias' => 30,
        'dias_espera_mora' => 15,
        'tasa_mora_diaria' => 1,
    ])->assertForbidden();
});

it('allows administrador_agencia to set an override for their own agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-credito-prendario', [
        'agencia_id' => $this->agencia->id,
        'tipo' => 'electro',
        'interes_default' => 12,
        'plazo_dias' => 20,
        'dias_espera_mora' => 10,
        'tasa_mora_diaria' => 1.5,
    ])->assertSuccessful()->assertJsonPath('data.agencia_id', $this->agencia->id);
});

it('denies administrador_agencia from setting an override for another agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-credito-prendario', [
        'agencia_id' => $otraAgencia->id,
        'tipo' => 'electro',
        'interes_default' => 12,
        'plazo_dias' => 20,
        'dias_espera_mora' => 10,
        'tasa_mora_diaria' => 1.5,
    ])->assertForbidden();
});

it('resolves the agencia override over the empresa default when registering a crédito', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa, 'electro')->create(['plazo_dias' => 30]);
    ConfiguracionCreditoPrendario::factory()->deAgencia($this->agencia, 'electro')->create(['plazo_dias' => 15]);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $bien = Bien::factory()->forAgencia($this->agencia)->create(['tipo' => 'electro']);
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.plazo_dias'))->toBe(15);
});

it('falls back to the empresa default when no agencia override exists', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa, 'electro')->create(['plazo_dias' => 30]);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $bien = Bien::factory()->forAgencia($this->agencia)->create(['tipo' => 'electro']);
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.plazo_dias'))->toBe(30);
});

it('rejects registering a crédito when no configuration exists at all', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $bien = Bien::factory()->forAgencia($this->agencia)->create(['tipo' => 'electro']);
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertUnprocessable();
});
