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
});

it('shows a preview of the boveda and every caja that would be force-closed', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);

    $response = $this->getJson("/api/bovedas/{$bovedaAgencia->id}/cierre/detalle")->assertSuccessful();

    expect($response->json('data.cajas'))->toHaveCount(2)
        ->and($response->json('data.saldo_boveda_actual'))->toBe('0.00')
        ->and($response->json('data.total_cajas'))->toBe('0.00')
        ->and($response->json('data.total_estimado_cierre'))->toBe('0.00');
});

it('cascades cerrar-forzado to close every open caja beneath an agencia boveda', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();

    // Blocked while cajas are open underneath, same as a plain cerrar().
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->postJson("/api/bovedas/{$bovedaAgencia->id}/cerrar", ['monto_contado' => 0])
        ->assertUnprocessable();

    $this->postJson("/api/bovedas/{$bovedaAgencia->id}/cerrar-forzado", ['monto_contado' => 0])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'cerrada');

    $cajaAsesor = Caja::query()->where('user_id', $asesor->id)->firstOrFail();
    $cajaSupervisor = Caja::query()->where('user_id', $supervisor->id)->firstOrFail();

    expect($cajaAsesor->ciclos()->latest()->firstOrFail())
        ->estado->toBe('cerrada')
        ->cierre_forzado->toBeTrue()
        ->and($cajaSupervisor->ciclos()->latest()->firstOrFail())
        ->estado->toBe('cerrada')
        ->cierre_forzado->toBeTrue()
        ->and($bovedaAgencia->fresh()->cicloAbierto()->exists())->toBeFalse();
});

it('denies an administrador_agencia from cerrar-forzado on a boveda of a different agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $bovedaOtraAgencia = Boveda::factory()->deAgencia($otraAgencia)->create();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/bovedas/{$bovedaOtraAgencia->id}/cerrar-forzado", ['monto_contado' => 0])
        ->assertForbidden();

    $this->getJson("/api/bovedas/{$bovedaOtraAgencia->id}/cierre/detalle")
        ->assertForbidden();
});
