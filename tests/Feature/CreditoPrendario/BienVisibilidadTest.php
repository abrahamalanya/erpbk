<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
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

    $this->adminAgencia1 = User::factory()->forAgencia($this->agencia1)->create();
    $this->adminAgencia1->assignRole('administrador_agencia');

    $this->clienteAsesor1 = Cliente::factory()->asignadoA($this->asesor1)->create();
    $this->clienteAsesor1b = Cliente::factory()->asignadoA($this->asesor1b)->create();
    $this->clienteAgencia2 = Cliente::factory()->forAgencia($this->agencia2)->create();

    $this->bienAsesor1 = Bien::factory()->paraCliente($this->clienteAsesor1)->create();
    $this->bienAsesor1b = Bien::factory()->paraCliente($this->clienteAsesor1b)->create();
    $this->bienAgencia2 = Bien::factory()->paraCliente($this->clienteAgencia2)->create();
});

it('scopes administrador_agencia to bienes in their own agencia', function () {
    Sanctum::actingAs($this->adminAgencia1, ['*']);

    $response = $this->getJson('/api/bienes')->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($this->bienAsesor1->id, $this->bienAsesor1b->id)
        ->not->toContain($this->bienAgencia2->id);
});

it('scopes supervisor to bienes of clientes belonging to their asesores', function () {
    Sanctum::actingAs($this->supervisor1, ['*']);

    $response = $this->getJson('/api/bienes')->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($this->bienAsesor1->id)
        ->not->toContain($this->bienAsesor1b->id, $this->bienAgencia2->id);
});

it('scopes asesor to only bienes of their own clientes', function () {
    Sanctum::actingAs($this->asesor1, ['*']);

    $response = $this->getJson('/api/bienes')->assertSuccessful()->assertJsonCount(1, 'data.data');

    expect($response->json('data.data.0.id'))->toBe($this->bienAsesor1->id);
});

it('denies an asesor from viewing a bien of another asesor\'s cliente', function () {
    Sanctum::actingAs($this->asesor1, ['*']);

    $this->getJson("/api/bienes/{$this->bienAsesor1b->id}")->assertForbidden();
});
