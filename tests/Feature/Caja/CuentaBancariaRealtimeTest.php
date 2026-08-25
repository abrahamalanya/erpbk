<?php

use App\Modules\Caja\Events\BovedaActualizada;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Models\Banco;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->getJson('/api/bovedas')->assertSuccessful();
    $this->boveda = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();
    $this->postJson("/api/bovedas/{$this->boveda->id}/aperturar", ['saldo_inicial' => 1000])->assertCreated();

    $this->banco = Banco::factory()->create();
});

it('broadcasts saldo_actual as efectivo plus cuentas bancarias when a cuenta bancaria is created', function () {
    Event::fake([BovedaActualizada::class]);

    $this->postJson("/api/bovedas/{$this->boveda->id}/cuentas-bancarias", [
        'banco_id' => $this->banco->id,
        'numero_cuenta' => '191-1',
        'titular' => 'Empresa SAC',
        'saldo_inicial' => 300,
    ])->assertCreated();

    Event::assertDispatched(BovedaActualizada::class, fn (BovedaActualizada $event): bool => (int) $event->boveda->id === (int) $this->boveda->id
        && $event->saldoActual === '1300.00');
});

it('broadcasts the updated total when a movimiento is registered on a cuenta bancaria', function () {
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->boveda)->create(['saldo_inicial' => 300]);

    Event::fake([BovedaActualizada::class]);

    $this->postJson("/api/cuentas-bancarias/{$cuenta->id}/movimiento", ['tipo' => 'ingreso', 'monto' => 200])
        ->assertCreated();

    Event::assertDispatched(BovedaActualizada::class, fn (BovedaActualizada $event): bool => (int) $event->boveda->id === (int) $this->boveda->id
        && $event->saldoActual === '1500.00');
});

it('excludes an inactive cuenta bancaria from the broadcast total', function () {
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->boveda)->create(['saldo_inicial' => 300]);

    Event::fake([BovedaActualizada::class]);

    $this->putJson("/api/cuentas-bancarias/{$cuenta->id}", ['activa' => false])->assertSuccessful();

    Event::assertDispatched(BovedaActualizada::class, fn (BovedaActualizada $event): bool => (int) $event->boveda->id === (int) $this->boveda->id
        && $event->saldoActual === '1000.00');
});

it('includes cuentas bancarias in the total when the principal receives an inyección', function () {
    CuentaBancaria::factory()->paraBoveda($this->boveda)->create(['saldo_inicial' => 300]);

    Event::fake([BovedaActualizada::class]);

    $this->postJson("/api/bovedas/{$this->boveda->id}/inyectar", ['monto' => 500])->assertCreated();

    Event::assertDispatched(BovedaActualizada::class, fn (BovedaActualizada $event): bool => (int) $event->boveda->id === (int) $this->boveda->id
        && $event->saldoActual === '1800.00');
});
