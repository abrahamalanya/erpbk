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
    $this->agenciaA = Agencia::factory()->for($this->empresa)->create();
    $this->agenciaB = Agencia::factory()->for($this->empresa)->create();
});

it('lets an asesor see only their own caja in the index', function () {
    $asesorA = User::factory()->forAgencia($this->agenciaA)->create();
    $asesorA->assignRole('asesor');
    $asesorB = User::factory()->forAgencia($this->agenciaB)->create();
    $asesorB->assignRole('asesor');

    Sanctum::actingAs($asesorA, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    Sanctum::actingAs($asesorB, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    Sanctum::actingAs($asesorA, ['*']);
    $response = $this->getJson('/api/cajas')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.user_id'))->toBe($asesorA->id);
});

it('lets an administrador_agencia see every caja in their own agencia only', function () {
    $asesorA = User::factory()->forAgencia($this->agenciaA)->create();
    $asesorA->assignRole('asesor');
    Sanctum::actingAs($asesorA, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $asesorB = User::factory()->forAgencia($this->agenciaB)->create();
    $asesorB->assignRole('asesor');
    Sanctum::actingAs($asesorB, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $administradorAgenciaA = User::factory()->forAgencia($this->agenciaA)->create();
    $administradorAgenciaA->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgenciaA, ['*']);

    $response = $this->getJson('/api/cajas')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('agencia_id')->unique()->all())->toBe([$this->agenciaA->id]);
});

it('lets administrador_general see cajas across every agencia of their empresa', function () {
    $asesorA = User::factory()->forAgencia($this->agenciaA)->create();
    $asesorA->assignRole('asesor');
    Sanctum::actingAs($asesorA, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $asesorB = User::factory()->forAgencia($this->agenciaB)->create();
    $asesorB->assignRole('asesor');
    Sanctum::actingAs($asesorB, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);

    $this->getJson('/api/cajas')->assertSuccessful()->assertJsonCount(2, 'data.data');
});

it('only shows a supervisor their own billetajes, not other agencias', function () {
    $asesorA = User::factory()->forAgencia($this->agenciaA)->create();
    $asesorA->assignRole('asesor');
    Sanctum::actingAs($asesorA, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 40])->assertCreated();

    $supervisorB = User::factory()->forAgencia($this->agenciaB)->create();
    $supervisorB->assignRole('supervisor');
    Sanctum::actingAs($supervisorB, ['*']);

    $this->getJson('/api/billetajes')->assertSuccessful()->assertJsonCount(0, 'data.data');
});
