<?php

use App\Models\Agencia;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->empresaA = Empresa::factory()->create();
    $this->empresaB = Empresa::factory()->create();

    $this->agenciaA = Agencia::factory()->for($this->empresaA)->create();
    $this->agenciaB = Agencia::factory()->for($this->empresaB)->create();

    User::factory()->count(3)->forEmpresa($this->empresaA)->create();
    User::factory()->count(2)->forEmpresa($this->empresaB)->create();
});

it('returns 404, not 403, when guessing another empresa agencia id', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson("/api/agencias/{$this->agenciaB->id}")->assertNotFound();
});

it('scopes User::count() to the authenticated tenant', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    // 3 seeded in beforeEach + this admin = 4 users in Empresa A
    expect(User::count())->toBe(4);
});

it('bypasses tenant scoping entirely for sistemas', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    expect(Agencia::count())->toBe(2);
});
