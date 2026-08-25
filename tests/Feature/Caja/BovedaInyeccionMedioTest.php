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
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->getJson('/api/bovedas')->assertSuccessful();
    $this->bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();
    $this->bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/aperturar", ['saldo_inicial' => 5000])->assertCreated();

    $this->banco = Banco::factory()->create();
});

it('injects capital directly into a cuenta bancaria of the principal boveda', function () {
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", [
        'monto' => 800,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuenta->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'ingreso')
        ->assertJsonPath('data.monto', '800.00')
        ->assertJsonPath('data.origen', 'inyeccion');

    expect($cuenta->fresh()->saldoActual())->toBe('800.00');
    // Cash saldo on the principal must be untouched — the injection landed in the bank account only.
    $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}")
        ->assertJsonPath('data.ciclo_abierto.saldo_actual', '5000.00')
        ->assertJsonPath('data.saldo_total', '5800.00');
});

it('requires a cuenta_bancaria_id when medio is cuenta_bancaria', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 100, 'medio' => 'cuenta_bancaria'])
        ->assertUnprocessable();
});

it('rejects a cuenta bancaria that does not belong to the target boveda', function () {
    $otraBoveda = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $cuentaDeOtraBoveda = CuentaBancaria::factory()->paraBoveda($otraBoveda)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", [
        'monto' => 100,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuentaDeOtraBoveda->id,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La cuenta bancaria seleccionada no pertenece a esta bóveda o está inactiva.');
});

it('requires a cuenta_bancaria_origen_id when traspasando a un agencia cuenta bancaria', function () {
    $cuentaAgencia = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", [
        'monto' => 500,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuentaAgencia->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cuenta_bancaria_origen_id');
});

it('traspasa bank-to-bank: debits the principal cuenta bancaria origen, not its cash ciclo', function () {
    $cuentaPrincipal = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id, 'saldo_inicial' => 1000]);
    $cuentaAgencia = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", [
        'monto' => 500,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuentaAgencia->id,
        'cuenta_bancaria_origen_id' => $cuentaPrincipal->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.origen', 'traspaso');

    expect($cuentaAgencia->fresh()->saldoActual())->toBe('500.00')
        ->and($cuentaPrincipal->fresh()->saldoActual())->toBe('500.00');

    // The principal's cash is untouched — the whole transfer stayed within the bank accounts.
    $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}")
        ->assertJsonPath('data.ciclo_abierto.saldo_actual', '5000.00');

    // The agencia's own cash ciclo was never auto-opened — bank transfers don't need it.
    $this->getJson("/api/bovedas/{$this->bovedaAgencia->id}")
        ->assertJsonPath('data.ciclo_abierto', null)
        ->assertJsonPath('data.saldo_cuentas_bancarias', '500.00');
});

it('validates the origen cuenta bancaria has enough saldo for a bank-to-bank traspaso', function () {
    $cuentaPrincipal = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id, 'saldo_inicial' => 100]);
    $cuentaAgencia = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", [
        'monto' => 500,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuentaAgencia->id,
        'cuenta_bancaria_origen_id' => $cuentaPrincipal->id,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La cuenta bancaria de origen no tiene saldo suficiente para este traspaso.');
});

it('rejects a cuenta bancaria origen that does not belong to the principal', function () {
    $cuentaAgencia = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create(['banco_id' => $this->banco->id]);
    $cuentaDestino = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", [
        'monto' => 500,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuentaDestino->id,
        'cuenta_bancaria_origen_id' => $cuentaAgencia->id,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La cuenta bancaria seleccionada no pertenece a esta bóveda o está inactiva.');
});
