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

it('requires an agencia when an agencia-level role is added to an empresa-level user', function () {
    $empresa = Empresa::factory()->create();
    Agencia::factory()->for($empresa)->create();

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forEmpresa($empresa)->create();
    $target->assignRole('administrador_general');

    $this->putJson("/api/usuarios/{$target->id}", [
        'roles' => ['administrador_general', 'supervisor'],
    ])->assertUnprocessable()->assertJsonValidationErrors('agencia_id');

    expect($target->fresh()->getRoleNames()->all())->toBe(['administrador_general']);
});

it('assigns the agencia when adding a supervisor role, so the user shows up as a supervisor there', function () {
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forEmpresa($empresa)->create();
    $target->assignRole('administrador_general');

    $this->putJson("/api/usuarios/{$target->id}", [
        'roles' => ['administrador_general', 'supervisor'],
        'agencia_id' => $agencia->id,
    ])->assertSuccessful();

    expect($target->fresh()->agencia_id)->toBe($agencia->id);

    $this->getJson("/api/usuarios?role=supervisor&agencia_id={$agencia->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.id', $target->id);
});

it('rejects an agencia that belongs to a different empresa', function () {
    $empresa = Empresa::factory()->create();
    $otraEmpresa = Empresa::factory()->create();
    $agenciaAjena = Agencia::factory()->for($otraEmpresa)->create();

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forEmpresa($empresa)->create();
    $target->assignRole('administrador_general');

    $this->putJson("/api/usuarios/{$target->id}", [
        'roles' => ['administrador_general', 'supervisor'],
        'agencia_id' => $agenciaAjena->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('agencia_id');
});

it('clears the agencia when the user drops every agencia-level role', function () {
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();

    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forAgencia($agencia)->create();
    $target->assignRole(['administrador_general', 'supervisor']);

    $this->putJson("/api/usuarios/{$target->id}", [
        'roles' => ['administrador_general'],
    ])->assertSuccessful();

    expect($target->fresh()->agencia_id)->toBeNull();
});

it('forces the administrador_agencia actor own agencia, ignoring any submitted one', function () {
    $empresa = Empresa::factory()->create();
    $agenciaPropia = Agencia::factory()->for($empresa)->create();
    $agenciaOtra = Agencia::factory()->for($empresa)->create();

    $adminAgencia = User::factory()->forAgencia($agenciaPropia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $target = User::factory()->forAgencia($agenciaPropia)->create();
    $target->assignRole('asesor');

    $this->putJson("/api/usuarios/{$target->id}", [
        'roles' => ['asesor', 'supervisor'],
        'agencia_id' => $agenciaOtra->id,
    ])->assertSuccessful();

    expect($target->fresh()->agencia_id)->toBe($agenciaPropia->id);
});
