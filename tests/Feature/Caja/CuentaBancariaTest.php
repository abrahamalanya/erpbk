<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Models\Banco;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->banco = Banco::factory()->create(['nombre' => 'Interbank']);

    // Provisions the principal + agencia bóvedas, mirroring BovedaAperturaTest.
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $this->bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();
    $this->bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
});

it('lets administrador_general create a cuenta bancaria on the principal boveda', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/cuentas-bancarias", [
        'banco_id' => $this->banco->id,
        'numero_cuenta' => '191-1234567-0-01',
        'titular' => 'Empresa SAC',
        'tipo_cuenta' => 'corriente',
        'moneda' => 'PEN',
        'saldo_inicial' => 1000,
    ])
        ->assertCreated()
        ->assertJsonPath('data.numero_cuenta', '191-1234567-0-01')
        ->assertJsonPath('data.banco.nombre', 'Interbank');

    expect(CuentaBancaria::query()->where('boveda_id', $this->bovedaPrincipal->id)->exists())->toBeTrue();
});

it('lets administrador_agencia create a cuenta bancaria only on their own agencia boveda', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/cuentas-bancarias", [
        'banco_id' => $this->banco->id,
        'numero_cuenta' => '003-1',
        'titular' => 'Agencia SAC',
    ])->assertCreated();

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/cuentas-bancarias", [
        'banco_id' => $this->banco->id,
        'numero_cuenta' => '003-2',
        'titular' => 'Empresa SAC',
    ])->assertForbidden();
});

it('denies asesor from managing cuentas bancarias', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/cuentas-bancarias", [
        'banco_id' => $this->banco->id,
        'numero_cuenta' => '003-1',
        'titular' => 'Agencia SAC',
    ])->assertForbidden();
});

it('lists cuentas bancarias with a live saldo_actual', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['saldo_inicial' => 500]);

    $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/cuentas-bancarias")
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $cuenta->id)
        ->assertJsonPath('data.0.saldo_actual', '500.00');
});

it('updates a cuenta bancaria but never its saldo_inicial via the update endpoint', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['saldo_inicial' => 500, 'titular' => 'Original']);

    $this->putJson("/api/cuentas-bancarias/{$cuenta->id}", ['titular' => 'Actualizado', 'saldo_inicial' => 999999])
        ->assertSuccessful()
        ->assertJsonPath('data.titular', 'Actualizado');

    expect($cuenta->fresh()->saldo_inicial)->toBe('500.00');
});

it('refuses to delete a cuenta bancaria that already has movimientos', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create();
    $this->postJson("/api/cuentas-bancarias/{$cuenta->id}/movimiento", ['tipo' => 'ingreso', 'monto' => 100])->assertCreated();

    $this->deleteJson("/api/cuentas-bancarias/{$cuenta->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se puede eliminar una cuenta bancaria con movimientos o conciliaciones registradas. Desactívala en su lugar.');
});

it('deletes a cuenta bancaria with no movimientos', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create();

    $this->deleteJson("/api/cuentas-bancarias/{$cuenta->id}")->assertSuccessful();
    expect(CuentaBancaria::query()->find($cuenta->id))->toBeNull();
});

it('requires numero_yape when acepta_yape is true', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/cuentas-bancarias", [
        'banco_id' => $this->banco->id,
        'numero_cuenta' => '191-1',
        'titular' => 'Empresa SAC',
        'acepta_yape' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('numero_yape');

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/cuentas-bancarias", [
        'banco_id' => $this->banco->id,
        'numero_cuenta' => '191-2',
        'titular' => 'Empresa SAC',
        'acepta_yape' => true,
        'numero_yape' => '999888777',
    ])->assertCreated()->assertJsonPath('data.acepta_yape', true);
});

it('exposes the boveda saldo as efectivo plus active cuentas bancarias', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/aperturar", ['saldo_inicial' => 1000])->assertCreated();

    CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['saldo_inicial' => 300, 'activa' => true]);
    CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['saldo_inicial' => 700, 'activa' => false]);

    $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.ciclo_abierto.saldo_actual', '1000.00')
        ->assertJsonPath('data.saldo_cuentas_bancarias', '300.00')
        ->assertJsonPath('data.saldo_total', '1300.00');
});
