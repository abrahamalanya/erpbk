<?php

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

it('allows administrador_agencia to force-close an asesor caja in their agencia', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $caja = Caja::query()->where('user_id', $asesor->id)->firstOrFail();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $response = $this->postJson("/api/cajas/{$caja->id}/cerrar-forzado", ['monto_contado' => 30])
        ->assertSuccessful();

    expect($response->json('data.estado'))->toBe('cerrada')
        ->and($response->json('data.cierre_forzado'))->toBeTrue();
});

it('denies a supervisor from force-closing an asesor caja (wrong level)', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $caja = Caja::query()->where('user_id', $asesor->id)->firstOrFail();

    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    Sanctum::actingAs($supervisor, ['*']);

    $this->postJson("/api/cajas/{$caja->id}/cerrar-forzado", ['monto_contado' => 30])
        ->assertForbidden();
});

it('denies an administrador_agencia from another agencia from force-closing', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $caja = Caja::query()->where('user_id', $asesor->id)->firstOrFail();

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroAdministrador = User::factory()->forAgencia($otraAgencia)->create();
    $otroAdministrador->assignRole('administrador_agencia');
    Sanctum::actingAs($otroAdministrador, ['*']);

    $this->postJson("/api/cajas/{$caja->id}/cerrar-forzado", ['monto_contado' => 30])
        ->assertForbidden();
});

it('auto-rejects pending billetajes when force-closing a caja', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 100, 'motivo' => 'Gastos operativos', 'medio_recepcion' => 'efectivo'])->assertCreated();

    $caja = Caja::query()->where('user_id', $asesor->id)->firstOrFail();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/cajas/{$caja->id}/cerrar-forzado", ['monto_contado' => 0])->assertSuccessful();

    $ciclo = $caja->ciclos()->latest()->firstOrFail();

    expect($ciclo->billetajes()->latest()->firstOrFail()->estado)->toBe('rechazado');
});

it('allows administrador_general to force-close an administrador_agencia caja', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $caja = Caja::query()->where('user_id', $adminAgencia->id)->firstOrFail();

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);

    $this->postJson("/api/cajas/{$caja->id}/cerrar-forzado", ['monto_contado' => 15])
        ->assertSuccessful()
        ->assertJsonPath('data.cierre_forzado', true);
});
