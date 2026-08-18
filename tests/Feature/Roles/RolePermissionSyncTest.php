<?php

use App\Modules\Sistemas\Models\Role;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('denies non-sistemas roles from accessing the roles module', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $role = Role::where('name', 'secretaria')->firstOrFail();

    $this->getJson('/api/roles')->assertForbidden();
    $this->getJson("/api/roles/{$role->id}")->assertForbidden();
    $this->putJson("/api/roles/{$role->id}", ['permissions' => []])->assertForbidden();
});

it('allows sistemas to sync a role permissions', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $role = Role::where('name', 'secretaria')->firstOrFail();

    $this->putJson("/api/roles/{$role->id}", ['permissions' => ['empresas.ver']])
        ->assertSuccessful();

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['empresas.ver']);
});

it('prevents editing the sistemas role permissions, even as sistemas', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $role = Role::where('name', 'sistemas')->firstOrFail();

    $this->putJson("/api/roles/{$role->id}", ['permissions' => []])->assertForbidden();
});

it('lists permissions for the roles module', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->getJson('/api/permisos')
        ->assertSuccessful()
        ->assertJsonCount(36, 'data');
});
