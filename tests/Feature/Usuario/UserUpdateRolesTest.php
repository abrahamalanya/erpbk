<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('adds a second role to an existing user', function () {
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forAgencia($agencia)->create();
    $target->assignRole('supervisor');

    $this->putJson("/api/usuarios/{$target->id}", [
        'roles' => ['supervisor', 'asesor'],
    ])->assertSuccessful();

    expect($target->fresh()->getRoleNames()->sort()->values()->all())
        ->toBe(['asesor', 'supervisor']);
});

it('rejects updating roles to one outside the actor hierarchy', function () {
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();

    $adminAgencia = User::factory()->forAgencia($agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $target = User::factory()->forAgencia($agencia)->create();
    $target->assignRole('asesor');

    $this->putJson("/api/usuarios/{$target->id}", [
        'roles' => ['asesor', 'administrador_general'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('roles.1');
});

it('leaves roles untouched when the request omits them', function () {
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forAgencia($agencia)->create();
    $target->assignRole('supervisor');

    $this->putJson("/api/usuarios/{$target->id}", [
        'nombre' => 'Nuevo Nombre',
    ])->assertSuccessful();

    expect($target->fresh()->getRoleNames()->all())->toBe(['supervisor']);
});
