<?php

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresaA = Empresa::factory()->create();
    $this->empresaB = Empresa::factory()->create();
});

it('allows sistemas to list all empresas', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->getJson('/api/empresas')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data.data');
});

it('denies administrador_general from listing empresas', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson('/api/empresas')->assertForbidden();
});

it('allows administrador_general to view their own empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson("/api/empresas/{$this->empresaA->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $this->empresaA->id);
});

it('denies administrador_general from viewing another empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson("/api/empresas/{$this->empresaB->id}")->assertForbidden();
});

it('allows administrador_general to update their own empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->putJson("/api/empresas/{$this->empresaA->id}", [
        'nombre' => 'razon actualizada',
        'ruc' => '20999999999',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.nombre', 'razon actualizada')
        ->assertJsonPath('data.ruc', '20999999999');
});

it('denies administrador_general from updating another empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->putJson("/api/empresas/{$this->empresaB->id}", ['nombre' => 'X'])->assertForbidden();
});

it('denies administrador_general from creating or deleting empresas', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson('/api/empresas', ['nombre' => 'Nueva'])->assertForbidden();
    $this->deleteJson("/api/empresas/{$this->empresaA->id}")->assertForbidden();
});

it('allows sistemas to fully manage empresas', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $created = $this->postJson('/api/empresas', ['nombre' => 'Nueva Empresa'])
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'Nueva Empresa');

    $id = $created->json('data.id');

    $this->putJson("/api/empresas/{$id}", ['nombre' => 'Actualizada'])
        ->assertSuccessful()
        ->assertJsonPath('data.nombre', 'Actualizada');

    $this->deleteJson("/api/empresas/{$id}")->assertSuccessful();

    expect(Empresa::find($id))->toBeNull();
});
