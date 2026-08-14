<?php

use App\Models\Agencia;
use App\Models\Empresa;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('allows sistemas to create a user for any empresa and agencia, with any assignable role', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();

    $response = $this->postJson('/api/usuarios', [
        'nombre' => 'Nuevo',
        'apellido' => 'Supervisor',
        'email' => 'nuevo.supervisor@test.com',
        'password' => 'password123',
        'role' => 'supervisor',
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
    ])->assertCreated();

    expect($response->json('data.empresa_id'))->toBe($empresa->id)
        ->and($response->json('data.agencia_id'))->toBe($agencia->id)
        ->and(collect($response->json('data.roles'))->pluck('name')->all())->toBe(['supervisor']);
});

it('forces administrador_general empresa_id even if a different one is submitted', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $agenciaA = Agencia::factory()->for($empresaA)->create();

    $admin = User::factory()->forEmpresa($empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->postJson('/api/usuarios', [
        'nombre' => 'Nuevo',
        'apellido' => 'AdminAgencia',
        'email' => 'nuevo.admin.agencia@test.com',
        'password' => 'password123',
        'role' => 'administrador_agencia',
        'empresa_id' => $empresaB->id,
        'agencia_id' => $agenciaA->id,
    ])->assertCreated();

    expect($response->json('data.empresa_id'))->toBe($empresaA->id);
});

it('forces administrador_agencia agencia_id even if a different one is submitted', function () {
    $empresa = Empresa::factory()->create();
    $agenciaOwn = Agencia::factory()->for($empresa)->create();
    $agenciaOther = Agencia::factory()->for($empresa)->create();

    $adminAgencia = User::factory()->forAgencia($agenciaOwn)->create();
    $adminAgencia->assignRole('administrador_agencia');

    $supervisor = User::factory()->forAgencia($agenciaOwn)->create();
    $supervisor->assignRole('supervisor');

    Sanctum::actingAs($adminAgencia, ['*']);

    $response = $this->postJson('/api/usuarios', [
        'nombre' => 'Nuevo',
        'apellido' => 'Asesor',
        'email' => 'nuevo.asesor@test.com',
        'password' => 'password123',
        'role' => 'asesor',
        'agencia_id' => $agenciaOther->id,
        'supervisor_id' => $supervisor->id,
    ])->assertCreated();

    expect($response->json('data.agencia_id'))->toBe($agenciaOwn->id);
});

it('rejects a supervisor from a different agencia', function () {
    $empresa = Empresa::factory()->create();
    $agenciaOwn = Agencia::factory()->for($empresa)->create();
    $agenciaOther = Agencia::factory()->for($empresa)->create();

    $adminAgencia = User::factory()->forAgencia($agenciaOwn)->create();
    $adminAgencia->assignRole('administrador_agencia');

    $foreignSupervisor = User::factory()->forAgencia($agenciaOther)->create();
    $foreignSupervisor->assignRole('supervisor');

    Sanctum::actingAs($adminAgencia, ['*']);

    $this->postJson('/api/usuarios', [
        'nombre' => 'Nuevo',
        'apellido' => 'Asesor',
        'email' => 'nuevo.asesor2@test.com',
        'password' => 'password123',
        'role' => 'asesor',
        'supervisor_id' => $foreignSupervisor->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('supervisor_id');
});

it('rejects assigning a role outside the actor hierarchy', function () {
    $agencia = Agencia::factory()->create();
    $adminAgencia = User::factory()->forAgencia($agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->postJson('/api/usuarios', [
        'nombre' => 'X',
        'apellido' => 'Y',
        'email' => 'nope@test.com',
        'password' => 'password123',
        'role' => 'administrador_general',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

it('rejects creation attempts from roles with no assignable roles', function (string $role) {
    $agencia = Agencia::factory()->create();
    $actor = User::factory()->forAgencia($agencia)->create();
    $actor->assignRole($role);
    Sanctum::actingAs($actor, ['*']);

    $this->postJson('/api/usuarios', [
        'nombre' => 'X',
        'apellido' => 'Y',
        'email' => 'blocked@test.com',
        'password' => 'password123',
        'role' => 'asesor',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('role');
})->with(['peinadora', 'supervisor', 'asesor']);

it('blocks creation via Gate when usuarios.crear is revoked, even though the role is structurally assignable', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');

    $role = Role::where('name', 'administrador_general')->firstOrFail();

    Sanctum::actingAs($sistemas, ['*']);
    $this->putJson("/api/roles/{$role->id}", ['permissions' => ['empresas.ver', 'agencias.ver']])
        ->assertSuccessful();

    $empresa = Empresa::factory()->create();
    $admin = User::factory()->forEmpresa($empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson('/api/usuarios', [
        'nombre' => 'X',
        'apellido' => 'Y',
        'email' => 'blocked2@test.com',
        'password' => 'password123',
        'role' => 'secretaria',
    ])->assertForbidden();
});
