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

    $this->agenciasA = Agencia::factory()->for($this->empresaA)->count(3)->create();
    $this->agenciaB = Agencia::factory()->for($this->empresaB)->create();
});

it('allows sistemas to list agencias across all empresas', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->getJson('/api/agencias')
        ->assertSuccessful()
        ->assertJsonCount(4, 'data.data');
});

it('scopes administrador_general to only their own empresa agencias', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/agencias')
        ->assertSuccessful()
        ->assertJsonCount(3, 'data.data');

    foreach ($response->json('data.data') as $agencia) {
        expect($agencia['empresa_id'])->toBe($this->empresaA->id);
    }
});

it('denies administrador_agencia from listing agencias', function () {
    $agencia = $this->agenciasA->first();
    $admin = User::factory()->forAgencia($agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson('/api/agencias')->assertForbidden();
});

it('allows administrador_agencia to view their own agencia', function () {
    $agencia = $this->agenciasA->first();
    $admin = User::factory()->forAgencia($agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson("/api/agencias/{$agencia->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $agencia->id);
});

it('denies administrador_agencia from viewing a sibling agencia in the same empresa', function () {
    $agencia = $this->agenciasA->first();
    $sibling = $this->agenciasA->last();
    $admin = User::factory()->forAgencia($agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson("/api/agencias/{$sibling->id}")->assertForbidden();
});

it('forces the actor empresa_id when administrador_general spoofs another empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson('/api/agencias', [
        'nombre' => 'Agencia Nueva',
        'empresa_id' => $this->empresaB->id,
    ])->assertCreated()
        ->assertJsonPath('data.empresa_id', $this->empresaA->id);
});

it('allows sistemas to create an agencia for any empresa', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->postJson('/api/agencias', [
        'nombre' => 'Agencia Nueva',
        'empresa_id' => $this->empresaB->id,
    ])->assertCreated()
        ->assertJsonPath('data.empresa_id', $this->empresaB->id);
});

it('denies agencia-level roles from creating, updating or deleting agencias', function (string $role) {
    $agencia = $this->agenciasA->first();
    $user = User::factory()->forAgencia($agencia)->create();
    $user->assignRole($role);
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/agencias', ['nombre' => 'X', 'empresa_id' => $this->empresaA->id])->assertForbidden();
    $this->putJson("/api/agencias/{$agencia->id}", ['nombre' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/agencias/{$agencia->id}")->assertForbidden();
})->with(['administrador_agencia', 'supervisor', 'asesor']);
