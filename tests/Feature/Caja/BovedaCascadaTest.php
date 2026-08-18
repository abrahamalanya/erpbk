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

it('opens the agencia boveda and the principal boveda when an agencia-level caja is opened', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/caja/aperturar')->assertCreated();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->first();
    $bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->first();

    expect($bovedaAgencia)->not->toBeNull()
        ->and($bovedaAgencia->cicloAbierto()->exists())->toBeTrue()
        ->and($bovedaPrincipal)->not->toBeNull()
        ->and($bovedaPrincipal->cicloAbierto()->exists())->toBeTrue();
});

it('opens only the principal boveda when an empresa-level caja is opened', function () {
    $secretaria = User::factory()->forEmpresa($this->empresa)->create();
    $secretaria->assignRole('secretaria');
    Sanctum::actingAs($secretaria, ['*']);

    $this->postJson('/api/caja/aperturar')->assertCreated();

    $bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->first();

    expect($bovedaPrincipal)->not->toBeNull()
        ->and($bovedaPrincipal->cicloAbierto()->exists())->toBeTrue()
        ->and(Boveda::query()->where('tipo', 'agencia')->exists())->toBeFalse();
});

it('reopens a previously closed boveda chain when a new caja opens', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/bovedas/{$bovedaAgencia->id}/cerrar", ['monto_contado' => 0])->assertSuccessful();

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->postJson("/api/bovedas/{$bovedaPrincipal->id}/cerrar", ['monto_contado' => 0])->assertSuccessful();

    expect($bovedaAgencia->fresh()->cicloAbierto()->exists())->toBeFalse()
        ->and($bovedaPrincipal->fresh()->cicloAbierto()->exists())->toBeFalse();

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    expect($bovedaAgencia->fresh()->cicloAbierto()->exists())->toBeTrue()
        ->and($bovedaPrincipal->fresh()->cicloAbierto()->exists())->toBeTrue();
});
