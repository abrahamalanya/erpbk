<?php

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

it('allows an asesor to aperturar their own caja', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $response = $this->postJson('/api/caja/aperturar')->assertCreated();

    expect($response->json('data.estado'))->toBe('abierta')
        ->and($response->json('data.saldo_apertura'))->toBe('0.00');
});

it('blocks a second apertura while a ciclo is still open', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/caja/aperturar')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Ya tienes un ciclo de caja abierto.');
});

it('closes a caja with arqueo and records the diferencia', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/caja/aperturar')->assertCreated();

    $response = $this->postJson('/api/caja/cerrar', ['monto_contado' => 50])->assertSuccessful();

    expect($response->json('data.estado'))->toBe('cerrada')
        ->and($response->json('data.saldo_calculado_cierre'))->toBe('0.00')
        ->and($response->json('data.saldo_arqueo_cierre'))->toBe('50.00')
        ->and($response->json('data.diferencia'))->toBe('50.00');
});

it('starts the next ciclo at zero after closing', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 200])->assertSuccessful();

    $response = $this->postJson('/api/caja/aperturar')->assertCreated();

    expect($response->json('data.saldo_apertura'))->toBe('0.00');
});

it('rejects cerrar when there is no open ciclo', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/caja/cerrar', ['monto_contado' => 10])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No tienes un ciclo de caja abierto.');
});

it('returns a friendly error instead of a database crash when sistemas (no empresa_id) opens /api/caja', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->getJson('/api/caja')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Este usuario no pertenece a una empresa y no participa en el módulo de Caja.');
});
