<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $this->boveda = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();

    $this->cuenta = CuentaBancaria::factory()->paraBoveda($this->boveda)->create(['saldo_inicial' => 500]);
});

it('registers an ingreso and updates the live saldo_actual', function () {
    $this->postJson("/api/cuentas-bancarias/{$this->cuenta->id}/movimiento", ['tipo' => 'ingreso', 'monto' => 200, 'concepto' => 'Depósito'])
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'ingreso')
        ->assertJsonPath('data.monto', '200.00');

    expect($this->cuenta->fresh()->saldoActual())->toBe('700.00');
});

it('registers an egreso when there is enough saldo', function () {
    $this->postJson("/api/cuentas-bancarias/{$this->cuenta->id}/movimiento", ['tipo' => 'egreso', 'monto' => 300])
        ->assertCreated();

    expect($this->cuenta->fresh()->saldoActual())->toBe('200.00');
});

it('rejects an egreso larger than the current saldo', function () {
    $this->postJson("/api/cuentas-bancarias/{$this->cuenta->id}/movimiento", ['tipo' => 'egreso', 'monto' => 501])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La cuenta bancaria no tiene saldo suficiente para este movimiento.');
});

it('lists movimientos paginated with a resumen of totals across every page', function () {
    $this->postJson("/api/cuentas-bancarias/{$this->cuenta->id}/movimiento", ['tipo' => 'ingreso', 'monto' => 100])->assertCreated();
    $this->postJson("/api/cuentas-bancarias/{$this->cuenta->id}/movimiento", ['tipo' => 'egreso', 'monto' => 40])->assertCreated();

    $this->getJson("/api/cuentas-bancarias/{$this->cuenta->id}/movimientos")
        ->assertSuccessful()
        ->assertJsonCount(2, 'data.movimientos.data')
        ->assertJsonPath('data.resumen.total_ingresos', '100.00')
        ->assertJsonPath('data.resumen.total_egresos', '40.00');
});
