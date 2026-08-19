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
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaAgenciaId}/inyectar", ['monto' => 1000])->assertCreated();

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

it('denies approving a billetaje when the funding boveda does not have enough saldo', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', ['monto' => 150])->json('data.id');

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    // Opened implicitly via the caja aperturar cascade above, at saldo 0.
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La bóveda no tiene saldo suficiente para entregar este billetaje.');
});

it('allows approving an asesor billetaje once the agencia boveda has been funded via a traspaso from the principal', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaAgenciaId}/inyectar", ['monto' => 300])
        ->assertCreated()
        ->assertJsonPath('data.concepto', 'Traspaso desde bóveda principal');

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeAsesorId = $this->postJson('/api/billetajes', ['monto' => 200])->json('data.id');

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeAsesorId}/aprobar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'aprobado');
});

it('denies a traspaso to an agencia boveda when the principal does not have enough saldo', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 100])->assertCreated();

    $this->postJson("/api/bovedas/{$bovedaAgenciaId}/inyectar", ['monto' => 300])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La bóveda principal no tiene saldo suficiente para este traspaso.');
});

it('routes an administrador_agencia billetaje request to their own agencia boveda, self-approved', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaAgenciaId}/inyectar", ['monto' => 300])->assertCreated();

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', ['monto' => 300])->json('data.id');

    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'aprobado')
        ->assertJsonPath('data.aprobado_por', $adminAgencia->id);

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaCiclo = $bovedaAgencia->cicloAbierto()->firstOrFail();

    expect($bovedaCiclo->movimientos()->where('tipo', 'egreso')->sum('monto'))->toEqual(300);
});
