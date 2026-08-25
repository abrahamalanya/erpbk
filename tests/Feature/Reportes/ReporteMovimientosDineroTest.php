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

it('lists cash and cuenta bancaria movimientos from every boveda visible to the actor', function () {
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", [
        'monto' => 300,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuenta->id,
    ])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 700])->assertCreated();

    $response = $this->getJson('/api/reportes/movimientos-dinero')->assertSuccessful();

    // 200 principal + 300 principal cuenta bancaria + (700 egreso principal + 700 ingreso agencia) traspaso.
    expect($response->json('data'))->toHaveCount(4)
        ->and(collect($response->json('data'))->pluck('boveda')->unique()->sort()->values()->all())
        ->toBe(collect(['Bóveda principal', $this->agencia->nombre])->sort()->values()->all());
});

it('filters by medio', function () {
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", [
        'monto' => 300,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuenta->id,
    ])->assertCreated();

    $this->getJson('/api/reportes/movimientos-dinero?medio=cuenta_bancaria')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.medio', 'cuenta_bancaria');
});

it('filters by fecha desde/hasta', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->assertCreated();

    $manana = now()->addDay()->toDateString();

    $this->getJson("/api/reportes/movimientos-dinero?desde={$manana}")
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

it('restricts administrador_agencia to only their own agencia boveda', function () {
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 700])->assertCreated();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $response = $this->getJson('/api/reportes/movimientos-dinero')->assertSuccessful();

    expect($response->json('data'))->not->toBeEmpty();
    foreach ($response->json('data') as $item) {
        expect($item['boveda'])->toBe($this->agencia->nombre);
    }
});

it('denies asesor from viewing the report', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->getJson('/api/reportes/movimientos-dinero')->assertForbidden();
});
