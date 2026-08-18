<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia1 = Agencia::factory()->for($this->empresa)->create();
    $this->agencia2 = Agencia::factory()->for($this->empresa)->create();

    $this->supervisor1 = User::factory()->forAgencia($this->agencia1)->create();
    $this->supervisor1->assignRole('supervisor');

    $this->supervisor1b = User::factory()->forAgencia($this->agencia1)->create();
    $this->supervisor1b->assignRole('supervisor');

    $this->asesor1 = User::factory()->forAgencia($this->agencia1)->create(['supervisor_id' => $this->supervisor1->id]);
    $this->asesor1->assignRole('asesor');

    $this->asesor1b = User::factory()->forAgencia($this->agencia1)->create(['supervisor_id' => $this->supervisor1b->id]);
    $this->asesor1b->assignRole('asesor');

    $this->peinadora1 = User::factory()->forAgencia($this->agencia1)->create();
    $this->peinadora1->assignRole('peinadora');

    $this->otraPeinadora = User::factory()->forAgencia($this->agencia1)->create();
    $this->otraPeinadora->assignRole('peinadora');

    $this->adminAgencia1 = User::factory()->forAgencia($this->agencia1)->create();
    $this->adminAgencia1->assignRole('administrador_agencia');

    $this->adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->adminGeneral->assignRole('administrador_general');

    $this->clienteAsesor1 = Cliente::factory()->registradoPor($this->peinadora1)->asignadoA($this->asesor1)->create();
    $this->clienteAsesor1b = Cliente::factory()->registradoPor($this->otraPeinadora)->asignadoA($this->asesor1b)->create();
    $this->clienteSinAsignarPeinadora1 = Cliente::factory()->registradoPor($this->peinadora1)->forAgencia($this->agencia1)->create();
    $this->clienteSinAsignarOtraPeinadora = Cliente::factory()->registradoPor($this->otraPeinadora)->forAgencia($this->agencia1)->create();
    $this->clienteAgencia2 = Cliente::factory()->forAgencia($this->agencia2)->create();
});

it('lets sistemas see every cliente', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->getJson('/api/clientes')->assertSuccessful()->assertJsonCount(5, 'data.data');
});

it('lets administrador_general see every cliente in the empresa', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);

    $this->getJson('/api/clientes')->assertSuccessful()->assertJsonCount(5, 'data.data');
});

it('scopes administrador_agencia to their own agencia', function () {
    Sanctum::actingAs($this->adminAgencia1, ['*']);

    $this->getJson('/api/clientes')->assertSuccessful()->assertJsonCount(4, 'data.data');
});

it('scopes supervisor to their subordinates plus unassigned clientes in their agencia', function () {
    Sanctum::actingAs($this->supervisor1, ['*']);

    $response = $this->getJson('/api/clientes')->assertSuccessful();
    $response->assertJsonCount(3, 'data.data');

    $ids = collect($response->json('data.data'))->pluck('id');
    expect($ids)->toContain($this->clienteAsesor1->id, $this->clienteSinAsignarPeinadora1->id, $this->clienteSinAsignarOtraPeinadora->id)
        ->not->toContain($this->clienteAsesor1b->id);
});

it('scopes asesor to only their own assigned clientes', function () {
    Sanctum::actingAs($this->asesor1, ['*']);

    $response = $this->getJson('/api/clientes')->assertSuccessful()->assertJsonCount(1, 'data.data');
    expect($response->json('data.data.0.id'))->toBe($this->clienteAsesor1->id);
});

it('scopes peinadora to only clientes she registered', function () {
    Sanctum::actingAs($this->peinadora1, ['*']);

    $response = $this->getJson('/api/clientes')->assertSuccessful()->assertJsonCount(2, 'data.data');
    $ids = collect($response->json('data.data'))->pluck('id');
    expect($ids)->toContain($this->clienteAsesor1->id, $this->clienteSinAsignarPeinadora1->id);
});
