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

it('denies administrador_agencia from assigning clientes', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->postJson("/api/clientes/{$this->cliente->id}/asignar", ['asesor_id' => $this->asesorPropio->id])
        ->assertForbidden();
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
