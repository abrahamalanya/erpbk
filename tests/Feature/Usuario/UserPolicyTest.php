<?php

use App\Models\Agencia;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresaA = Empresa::factory()->create();
    $this->empresaB = Empresa::factory()->create();

    $this->agenciaA1 = Agencia::factory()->for($this->empresaA)->create();
    $this->agenciaA2 = Agencia::factory()->for($this->empresaA)->create();
});

it('allows administrador_general to view any user within their own empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forAgencia($this->agenciaA1)->create();
    $target->assignRole('asesor');

    $this->getJson("/api/usuarios/{$target->id}")->assertSuccessful();
});

it('returns 404 (tenant-scoped) when administrador_general looks up a user in another empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forEmpresa($this->empresaB)->create();
    $target->assignRole('secretaria');

    $this->getJson("/api/usuarios/{$target->id}")->assertNotFound();
});

it('allows administrador_agencia to view a user within their own agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agenciaA1)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $target = User::factory()->forAgencia($this->agenciaA1)->create();
    $target->assignRole('asesor');

    $this->getJson("/api/usuarios/{$target->id}")->assertSuccessful();
});

it('denies administrador_agencia from viewing a user in a sibling agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agenciaA1)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $target = User::factory()->forAgencia($this->agenciaA2)->create();
    $target->assignRole('asesor');

    $this->getJson("/api/usuarios/{$target->id}")->assertForbidden();
});

it('denies roles without usuarios.ver from viewing users', function () {
    $asesor = User::factory()->forAgencia($this->agenciaA1)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $target = User::factory()->forAgencia($this->agenciaA1)->create();
    $target->assignRole('peinadora');

    $this->getJson('/api/usuarios')->assertForbidden();
    $this->getJson("/api/usuarios/{$target->id}")->assertForbidden();
});

it('scopes administrador_agencia index listing to their own agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agenciaA1)->create();
    $adminAgencia->assignRole('administrador_agencia');

    User::factory()->forAgencia($this->agenciaA1)->create()->assignRole('asesor');
    User::factory()->forAgencia($this->agenciaA2)->create()->assignRole('asesor');

    Sanctum::actingAs($adminAgencia, ['*']);

    $response = $this->getJson('/api/usuarios')->assertSuccessful();

    foreach ($response->json('data.data') as $user) {
        expect($user['agencia_id'])->toBe($this->agenciaA1->id);
    }
});
