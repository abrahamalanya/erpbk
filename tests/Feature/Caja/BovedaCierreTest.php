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

it('blocks closing the agencia boveda while a caja underneath is still open', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/bovedas/{$bovedaAgencia->id}/cerrar", ['monto_contado' => 0])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se puede cerrar la bóveda: hay cajas abiertas que dependen de ella.');
});

it('allows closing the agencia boveda once every caja underneath is closed', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/bovedas/{$bovedaAgencia->id}/cerrar", ['monto_contado' => 0])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'cerrada');
});

it('denies an administrador_agencia from closing a boveda of a different agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $bovedaOtraAgencia = Boveda::factory()->deAgencia($otraAgencia)->create();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/bovedas/{$bovedaOtraAgencia->id}/cerrar", ['monto_contado' => 0])
        ->assertForbidden();
});
