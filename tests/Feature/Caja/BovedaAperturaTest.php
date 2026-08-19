<?php

use App\Modules\Caja\Models\Boveda;
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

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');

    // Listing /api/bovedas as administrador_general provisions (firstOrCreate)
    // the empresa's principal bóveda row, mirroring how the frontend would
    // discover it before ever aperturar-ing.
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $this->bovedaId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
});

it('requires saldo_inicial the very first time the principal boveda is aperturada', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaId}/aperturar")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Debes ingresar el saldo inicial de la bóveda.');
});

it('aperturas the principal boveda with the given saldo_inicial the first time', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaId}/aperturar", ['saldo_inicial' => 5000])
        ->assertCreated()
        ->assertJsonPath('data.saldo_apertura', '5000.00')
        ->assertJsonPath('data.estado', 'abierta');
});

it('denies aperturar-ing a boveda that already has an open ciclo', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaId}/aperturar", ['saldo_inicial' => 1000])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La bóveda ya tiene un ciclo abierto.');
});

it('denies administrador_agencia from aperturar-ing or inyectar-ing the principal boveda', function () {
    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaId}/aperturar", ['saldo_inicial' => 1000])->assertForbidden();
    $this->postJson("/api/bovedas/{$this->bovedaId}/inyectar", ['monto' => 100])->assertForbidden();
});

it('denies inyectar-ing capital into a boveda without an open ciclo', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaId}/inyectar", ['monto' => 100])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La bóveda principal no tiene un ciclo abierto. Apertúrala primero.');
});

it('lets administrador_general inyectar additional capital once the boveda is open', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->postJson("/api/bovedas/{$this->bovedaId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();

    $this->postJson("/api/bovedas/{$this->bovedaId}/inyectar", ['monto' => 500, 'concepto' => 'Aporte adicional'])
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'ingreso')
        ->assertJsonPath('data.monto', '500.00')
        ->assertJsonPath('data.concepto', 'Aporte adicional');

    $this->getJson("/api/bovedas/{$this->bovedaId}")
        ->assertSuccessful()
        ->assertJsonPath('data.ciclo_abierto.saldo_actual', '1500.00');
});

it('does not require saldo_inicial again when reopening after a close, and carries the calculated close balance forward', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->postJson("/api/bovedas/{$this->bovedaId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    // monto_contado (1200) differs from the calculated close balance (1000,
    // since nothing moved in this ciclo) on purpose — the next apertura must
    // carry forward saldo_calculado_cierre, not the physically-counted arqueo.
    $this->postJson("/api/bovedas/{$this->bovedaId}/cerrar", ['monto_contado' => 1200])->assertSuccessful();

    $this->postJson("/api/bovedas/{$this->bovedaId}/aperturar")
        ->assertCreated()
        ->assertJsonPath('data.saldo_apertura', '1000.00');
});
