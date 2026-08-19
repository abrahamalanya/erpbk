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
});

it('blocks aperturar-ing a caja while its funding boveda has an open ciclo dated a previous day', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaAgencia->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);

    $this->postJson('/api/caja/aperturar')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No puedes aperturar tu caja: la bóveda que te financia sigue con un ciclo abierto de un día anterior.');
});

it('allows aperturar-ing again once the stale-dated boveda ciclo is closed', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaAgencia->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/bovedas/{$bovedaAgencia->id}/cerrar", ['monto_contado' => 0])->assertSuccessful();

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
});
