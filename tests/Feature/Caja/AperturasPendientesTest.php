<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
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

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
});

it('returns nothing pending for a user who never opened a caja', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('does not flag a caja that is open in the CURRENT cycle', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('flags the own caja as pending once its cycle is dated a previous day, and allows closing it', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    Caja::query()->where('user_id', $this->asesor->id)->firstOrFail()->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);

    $response = $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.tipo'))->toBe('caja')
        ->and($response->json('data.0.puede_cerrar'))->toBeTrue()
        ->and($response->json('data.0.rol_autorizado'))->toBeNull();

    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();
    $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('flags a pending agencia bóveda and lets the administrador_agencia close it, but not the asesor', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaAgencia->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);

    $comoAsesor = $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful();
    expect($comoAsesor->json('data'))->toHaveCount(1);
    expect($comoAsesor->json('data.0.tipo'))->toBe('boveda')
        ->and($comoAsesor->json('data.0.puede_cerrar'))->toBeFalse()
        ->and($comoAsesor->json('data.0.rol_autorizado'))->toBe('administrador_agencia');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $comoAdmin = $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful();
    expect($comoAdmin->json('data.0.puede_cerrar'))->toBeTrue();

    $this->postJson("/api/bovedas/{$bovedaAgencia->id}/cerrar", ['monto_contado' => 0])->assertSuccessful();
    $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('flags both the agencia AND the principal bóveda when both are stale, as two separate items', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();
    $bovedaAgencia->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);
    $bovedaPrincipal->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $response = $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful();

    $tipos = collect($response->json('data'))->pluck('tipo')->all();
    expect($tipos)->toContain('boveda')->and(count($tipos))->toBe(2);

    $rolesAutorizados = collect($response->json('data'))->pluck('rol_autorizado')->filter()->values()->all();
    expect($rolesAutorizados)->toContain('administrador_general');
});

it('returns an empty list for a role that does not participate in Caja, instead of erroring', function () {
    $sistemas = User::factory()->forEmpresa($this->empresa)->create();
    $sistemas->assignRole('sistemas');

    Sanctum::actingAs($sistemas, ['*']);
    $this->getJson('/api/caja/aperturas-pendientes')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('exposes ciclo_abierto_vigente on GET /caja and GET /bovedas/mia', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.ciclo_abierto_vigente', true);

    Caja::query()->where('user_id', $this->asesor->id)->firstOrFail()->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);
    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.ciclo_abierto_vigente', false);

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $this->getJson('/api/bovedas/mia')->assertSuccessful()->assertJsonPath('data.ciclo_abierto_vigente', true);

    $bovedaAgencia->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);
    $this->getJson('/api/bovedas/mia')->assertSuccessful()->assertJsonPath('data.ciclo_abierto_vigente', false);
});
