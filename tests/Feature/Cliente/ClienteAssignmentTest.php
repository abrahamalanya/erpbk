<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->otraAgencia = Agencia::factory()->for($this->empresa)->create();

    $this->supervisor = User::factory()->forAgencia($this->agencia)->create();
    $this->supervisor->assignRole('supervisor');

    $this->otroSupervisor = User::factory()->forAgencia($this->agencia)->create();
    $this->otroSupervisor->assignRole('supervisor');

    $this->asesorPropio = User::factory()->forAgencia($this->agencia)->create(['supervisor_id' => $this->supervisor->id]);
    $this->asesorPropio->assignRole('asesor');

    $this->asesorAjeno = User::factory()->forAgencia($this->agencia)->create(['supervisor_id' => $this->otroSupervisor->id]);
    $this->asesorAjeno->assignRole('asesor');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

it('allows a supervisor to assign an unassigned cliente to their own asesor', function () {
    Sanctum::actingAs($this->supervisor, ['*']);

    $this->postJson("/api/clientes/{$this->cliente->id}/asignar", ['asesor_id' => $this->asesorPropio->id])
        ->assertSuccessful();

    expect($this->cliente->fresh()->asesor_id)->toBe($this->asesorPropio->id);
});

it('rejects assigning to an asesor who reports to a different supervisor', function () {
    Sanctum::actingAs($this->supervisor, ['*']);

    $this->postJson("/api/clientes/{$this->cliente->id}/asignar", ['asesor_id' => $this->asesorAjeno->id])
        ->assertForbidden();
});

it('allows administrador_agencia to assign to any asesor of their agencia, not just a given supervisor\'s own', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->postJson("/api/clientes/{$this->cliente->id}/asignar", ['asesor_id' => $this->asesorAjeno->id])
        ->assertSuccessful();

    expect($this->cliente->fresh()->asesor_id)->toBe($this->asesorAjeno->id);
});

it('rejects administrador_agencia assigning a cliente to an asesor of a different agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    $asesorOtraAgencia = User::factory()->forAgencia($this->otraAgencia)->create();
    $asesorOtraAgencia->assignRole('asesor');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->postJson("/api/clientes/{$this->cliente->id}/asignar", ['asesor_id' => $asesorOtraAgencia->id])
        ->assertForbidden();
});

it('rejects administrador_agencia assigning a cliente of a different agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    $clienteOtraAgencia = Cliente::factory()->forAgencia($this->otraAgencia)->create();
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->postJson("/api/clientes/{$clienteOtraAgencia->id}/asignar", ['asesor_id' => $this->asesorPropio->id])
        ->assertForbidden();
});

it('allows administrador_general to assign to any asesor of the cliente\'s agencia, across the empresa', function () {
    $adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $adminGeneral->assignRole('administrador_general');
    Sanctum::actingAs($adminGeneral, ['*']);

    $this->postJson("/api/clientes/{$this->cliente->id}/asignar", ['asesor_id' => $this->asesorAjeno->id])
        ->assertSuccessful();

    expect($this->cliente->fresh()->asesor_id)->toBe($this->asesorAjeno->id);
});

it('allows sistemas to assign unconditionally', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->postJson("/api/clientes/{$this->cliente->id}/asignar", ['asesor_id' => $this->asesorAjeno->id])
        ->assertSuccessful();
});

it('rejects assigning a cliente that belongs to a different agencia', function () {
    $clienteOtraAgencia = Cliente::factory()->forAgencia($this->otraAgencia)->create();

    Sanctum::actingAs($this->supervisor, ['*']);

    $this->postJson("/api/clientes/{$clienteOtraAgencia->id}/asignar", ['asesor_id' => $this->asesorPropio->id])
        ->assertForbidden();
});

it('lists only the asesores of the requested agencia for administrador_general', function () {
    $adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $adminGeneral->assignRole('administrador_general');
    $asesorOtraAgencia = User::factory()->forAgencia($this->otraAgencia)->create();
    $asesorOtraAgencia->assignRole('asesor');
    Sanctum::actingAs($adminGeneral, ['*']);

    $response = $this->getJson("/api/clientes/asesores?agencia_id={$this->agencia->id}")->assertSuccessful();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->asesorPropio->id, $this->asesorAjeno->id)
        ->not->toContain($asesorOtraAgencia->id);
});

it('ignores the requested agencia_id for administrador_agencia, always scoping to their own', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $response = $this->getJson("/api/clientes/asesores?agencia_id={$this->otraAgencia->id}")->assertSuccessful();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->asesorPropio->id, $this->asesorAjeno->id);
});

it('only lists the supervisor\'s own subordinate asesores, never asesores of other supervisors', function () {
    Sanctum::actingAs($this->supervisor, ['*']);

    $response = $this->getJson('/api/clientes/asesores')->assertSuccessful();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->asesorPropio->id)
        ->not->toContain($this->asesorAjeno->id);
});

it('denies an asesor from listing asesores para asignar', function () {
    Sanctum::actingAs($this->asesorPropio, ['*']);

    $this->getJson('/api/clientes/asesores')->assertForbidden();
});
