<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\CreditoPrendario\Models\Bien;
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
        'interes_default' => 10,
        'plazo_dias' => 30,
        'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15,
        'tasa_mora_diaria' => 1,
    ])->assertSuccessful()->assertJsonPath('data.agencia_id', null);
});

it('denies administrador_agencia from setting the empresa-wide default (no agencia_id)', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-credito-prendario', [
        'interes_default' => 10,
        'plazo_dias' => 30,
        'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15,
        'tasa_mora_diaria' => 1,
    ])->assertForbidden();
});

it('allows administrador_agencia to set an override for their own agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-credito-prendario', [
        'agencia_id' => $this->agencia->id,
        'interes_default' => 12,
        'plazo_dias' => 20,
        'dias_espera_mora' => 10,
        'dias_minimo_interes' => 10,
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
        'interes_default' => 12,
        'plazo_dias' => 20,
        'dias_espera_mora' => 10,
        'dias_minimo_interes' => 10,
        'tasa_mora_diaria' => 1.5,
    ])->assertForbidden();
});

it('resolves the agencia override over the empresa default when registering a crédito', function () {
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create(['plazo_dias' => 30]);
    ConfiguracionCredito::factory()->deAgencia($this->agencia)->create(['plazo_dias' => 15]);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
    Sanctum::actingAs($asesor, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.plazo_dias'))->toBe(15);
});

it('falls back to the empresa default when no agencia override exists', function () {
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create(['plazo_dias' => 30]);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
    Sanctum::actingAs($asesor, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.plazo_dias'))->toBe(30);
});

it('rejects registering a crédito when no configuration exists at all', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
    Sanctum::actingAs($asesor, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertUnprocessable();
});
