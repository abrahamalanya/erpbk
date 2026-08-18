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

it('rejects a billetaje request when the caja is not open', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/billetajes', ['monto' => 100])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Debes aperturar tu caja antes de solicitar billetaje.');
});

it('allows several pending billetajes at once for the same caja', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/billetajes', ['monto' => 100])->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 50])->assertCreated();

    $this->getJson('/api/billetajes')->assertSuccessful()->assertJsonCount(2, 'data.data');
});

it('lets administrador_agencia approve a billetaje and creates matching movimientos on both sides', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', ['monto' => 150])->json('data.id');

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'aprobado')
        ->assertJsonPath('data.aprobado_por', $administradorAgencia->id);

    $boveda = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaCiclo = $boveda->cicloAbierto()->firstOrFail();

    expect($bovedaCiclo->movimientos()->where('tipo', 'egreso')->sum('monto'))->toEqual(150);

    Sanctum::actingAs($asesor, ['*']);
    $cierre = $this->postJson('/api/caja/cerrar', ['monto_contado' => 150])->assertSuccessful();

    expect($cierre->json('data.saldo_calculado_cierre'))->toBe('150.00');
});

it('lets administrador_agencia reject a billetaje with a motivo', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', ['monto' => 80])->json('data.id');

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/billetajes/{$billetajeId}/rechazar", ['motivo' => 'No corresponde'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'rechazado')
        ->assertJsonPath('data.motivo_rechazo', 'No corresponde');
});

it('denies an administrador_agencia from another agencia from approving', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', ['monto' => 80])->json('data.id');

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroAdministrador = User::factory()->forAgencia($otraAgencia)->create();
    $otroAdministrador->assignRole('administrador_agencia');
    Sanctum::actingAs($otroAdministrador, ['*']);

    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")->assertForbidden();
});

it('routes an administrador_agencia billetaje request to the empresa principal boveda, approved by administrador_general', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', ['monto' => 300])->json('data.id');

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);

    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'aprobado');

    $bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();
    $bovedaCiclo = $bovedaPrincipal->cicloAbierto()->firstOrFail();

    expect($bovedaCiclo->movimientos()->where('tipo', 'egreso')->sum('monto'))->toEqual(300);
});
