<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresaA = Empresa::factory()->create();
    $this->empresaB = Empresa::factory()->create();
    $this->agenciaA1 = Agencia::factory()->for($this->empresaA)->create();
    $this->agenciaA2 = Agencia::factory()->for($this->empresaA)->create();

    $this->sistemas = User::factory()->create();
    $this->sistemas->assignRole('sistemas');
});

it('filters by nombre (matches nombre or apellido)', function () {
    $juan = User::factory()->forEmpresa($this->empresaA)->create(['nombre' => 'Juan', 'apellido' => 'Perez']);
    $juan->assignRole('asesor');
    $maria = User::factory()->forEmpresa($this->empresaA)->create(['nombre' => 'Maria', 'apellido' => 'Juanez']);
    $maria->assignRole('asesor');
    $pedro = User::factory()->forEmpresa($this->empresaA)->create(['nombre' => 'Pedro', 'apellido' => 'Lopez']);
    $pedro->assignRole('asesor');

    Sanctum::actingAs($this->sistemas, ['*']);

    $response = $this->getJson('/api/usuarios?nombre=juan')->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($juan->id, $maria->id)->not->toContain($pedro->id);
});

it('filters by dni', function () {
    $target = User::factory()->forEmpresa($this->empresaA)->create(['dni' => '87654321']);
    $target->assignRole('asesor');
    $other = User::factory()->forEmpresa($this->empresaA)->create(['dni' => '11112222']);
    $other->assignRole('asesor');

    Sanctum::actingAs($this->sistemas, ['*']);

    $response = $this->getJson('/api/usuarios?dni=8765')->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($target->id)->not->toContain($other->id);
});

it('filters by estado', function () {
    $activo = User::factory()->forEmpresa($this->empresaA)->create(['estado' => 'activo']);
    $activo->assignRole('asesor');
    $inactivo = User::factory()->forEmpresa($this->empresaA)->create(['estado' => 'inactivo']);
    $inactivo->assignRole('asesor');

    Sanctum::actingAs($this->sistemas, ['*']);

    $response = $this->getJson('/api/usuarios?estado=inactivo')->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($inactivo->id)->not->toContain($activo->id);
});

it('filters by role', function () {
    $supervisor = User::factory()->forEmpresa($this->empresaA)->create();
    $supervisor->assignRole('supervisor');
    $asesor = User::factory()->forEmpresa($this->empresaA)->create();
    $asesor->assignRole('asesor');

    Sanctum::actingAs($this->sistemas, ['*']);

    $response = $this->getJson('/api/usuarios?role=supervisor')->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($supervisor->id)->not->toContain($asesor->id);
});

it('filters by agencia_id', function () {
    $enA1 = User::factory()->forAgencia($this->agenciaA1)->create();
    $enA1->assignRole('asesor');
    $enA2 = User::factory()->forAgencia($this->agenciaA2)->create();
    $enA2->assignRole('asesor');

    Sanctum::actingAs($this->sistemas, ['*']);

    $response = $this->getJson("/api/usuarios?agencia_id={$this->agenciaA1->id}")->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($enA1->id)->not->toContain($enA2->id);
});

it('lets sistemas filter by empresa_id', function () {
    $enA = User::factory()->forEmpresa($this->empresaA)->create();
    $enA->assignRole('asesor');
    $enB = User::factory()->forEmpresa($this->empresaB)->create();
    $enB->assignRole('asesor');

    Sanctum::actingAs($this->sistemas, ['*']);

    $response = $this->getJson("/api/usuarios?empresa_id={$this->empresaB->id}")->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($enB->id)->not->toContain($enA->id);
});

it('ignores the empresa_id filter for non-sistemas roles (already tenant-scoped)', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');

    $peer = User::factory()->forEmpresa($this->empresaA)->create();
    $peer->assignRole('asesor');

    Sanctum::actingAs($admin, ['*']);

    // Passing another empresa's id must not leak data across tenants.
    $response = $this->getJson("/api/usuarios?empresa_id={$this->empresaB->id}")->assertSuccessful();
    $ids = collect($response->json('data.data'))->pluck('id');

    expect($ids)->toContain($peer->id);
});
