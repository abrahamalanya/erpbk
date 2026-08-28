<?php

use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('returns the roles a sistemas user can assign', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->getJson('/api/usuarios/roles-asignables')
        ->assertSuccessful()
        ->assertJsonPath('data', [
            'administrador_general', 'secretaria', 'administrador_agencia', 'supervisor', 'peinadora', 'asesor',
        ]);
});

it('returns the narrower set for an administrador_agencia', function () {
    $adminAgencia = User::factory()->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->getJson('/api/usuarios/roles-asignables')
        ->assertSuccessful()
        ->assertJsonPath('data', ['supervisor', 'peinadora', 'asesor']);
});

it('returns the union when the actor holds several roles', function () {
    $actor = User::factory()->create();
    $actor->assignRole(['secretaria', 'administrador_agencia']);
    Sanctum::actingAs($actor, ['*']);

    $data = $this->getJson('/api/usuarios/roles-asignables')
        ->assertSuccessful()
        ->json('data');

    expect($data)->toEqualCanonicalizing(['administrador_agencia', 'supervisor', 'peinadora', 'asesor']);
});

it('forbids users without usuarios.crear', function () {
    $asesor = User::factory()->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->getJson('/api/usuarios/roles-asignables')->assertForbidden();
});
