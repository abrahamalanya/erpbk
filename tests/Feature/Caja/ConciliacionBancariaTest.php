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

it('records a conciliación with the diferencia between saldo_sistema and saldo_banco', function () {
    $this->postJson("/api/cuentas-bancarias/{$this->cuenta->id}/conciliar", ['saldo_banco' => 480, 'observacion' => 'Comisión de mantenimiento no registrada'])
        ->assertCreated()
        ->assertJsonPath('data.saldo_sistema', '500.00')
        ->assertJsonPath('data.saldo_banco', '480.00')
        ->assertJsonPath('data.diferencia', '-20.00');
});

it('does not alter the cuenta saldo_actual — it only records the comparison', function () {
    $this->postJson("/api/cuentas-bancarias/{$this->cuenta->id}/conciliar", ['saldo_banco' => 480])->assertCreated();

    expect($this->cuenta->fresh()->saldoActual())->toBe('500.00');
});

it('lists conciliaciones paginated', function () {
    $this->postJson("/api/cuentas-bancarias/{$this->cuenta->id}/conciliar", ['saldo_banco' => 500])->assertCreated();

    $this->getJson("/api/cuentas-bancarias/{$this->cuenta->id}/conciliaciones")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.data');
});
