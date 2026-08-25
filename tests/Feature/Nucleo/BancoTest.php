<?php

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Models\Banco;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();

    $this->sistemas = User::factory()->create();
    $this->sistemas->assignRole('sistemas');

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
});

it('lets any authenticated user list the global bancos catalog', function () {
    Banco::factory()->count(2)->create();
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->getJson('/api/bancos')->assertSuccessful()->assertJsonCount(2, 'data');
});

it('lets sistemas create a banco', function () {
    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/bancos', ['nombre' => 'BCP'])
        ->assertCreated()
        ->assertJsonPath('data.nombre', 'BCP')
        ->assertJsonPath('data.activo', true);

    expect(Banco::query()->where('nombre', 'BCP')->exists())->toBeTrue();
});

it('denies a non-sistemas user from creating a banco', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson('/api/bancos', ['nombre' => 'BCP'])->assertForbidden();
});

it('requires a unique nombre', function () {
    Banco::factory()->create(['nombre' => 'BCP']);
    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/bancos', ['nombre' => 'BCP'])->assertUnprocessable();
});

it('lets sistemas update and delete a banco', function () {
    $banco = Banco::factory()->create();
    Sanctum::actingAs($this->sistemas, ['*']);

    $this->putJson("/api/bancos/{$banco->id}", ['activo' => false])
        ->assertSuccessful()
        ->assertJsonPath('data.activo', false);

    $this->deleteJson("/api/bancos/{$banco->id}")->assertSuccessful();
    expect(Banco::query()->find($banco->id))->toBeNull();
});
