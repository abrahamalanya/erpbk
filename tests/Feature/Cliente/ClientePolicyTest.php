<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Role;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
});

it('allows peinadora to create a cliente', function () {
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');
    Sanctum::actingAs($peinadora, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '12345678',
    ])->assertCreated();
});

it('allows asesor to create a cliente', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '12345678',
    ])->assertCreated();
});

it('denies supervisor from creating a cliente (no clientes.crear by default)', function () {
    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    Sanctum::actingAs($supervisor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '12345678',
    ])->assertForbidden();
});

it('allows peinadora to update her own unassigned cliente', function () {
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');
    $cliente = Cliente::factory()->registradoPor($peinadora)->create();

    Sanctum::actingAs($peinadora, ['*']);

    $this->putJson("/api/clientes/{$cliente->id}", ['nombre' => 'Actualizado'])->assertSuccessful();
});

it('denies peinadora from updating a cliente once it has been assigned to an asesor', function () {
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');

    $cliente = Cliente::factory()->registradoPor($peinadora)->asignadoA($asesor)->create();

    Sanctum::actingAs($peinadora, ['*']);

    $this->putJson("/api/clientes/{$cliente->id}", ['nombre' => 'Actualizado'])->assertForbidden();
});

it('denies peinadora from updating a cliente registered by someone else', function () {
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');

    $otraPeinadora = User::factory()->forAgencia($this->agencia)->create();
    $otraPeinadora->assignRole('peinadora');

    $cliente = Cliente::factory()->registradoPor($otraPeinadora)->forAgencia($this->agencia)->create();

    Sanctum::actingAs($peinadora, ['*']);

    $this->putJson("/api/clientes/{$cliente->id}", ['nombre' => 'X'])->assertForbidden();
});

it('allows administrador_general to delete a cliente in their empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresa)->create();
    $admin->assignRole('administrador_general');
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($admin, ['*']);

    $this->deleteJson("/api/clientes/{$cliente->id}")->assertSuccessful();
});

it('denies administrador_agencia from deleting a cliente (no clientes.eliminar by default)', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($adminAgencia, ['*']);

    $this->deleteJson("/api/clientes/{$cliente->id}")->assertForbidden();
});

it('blocks creation via Gate when clientes.crear is revoked from peinadora', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');

    $role = Role::where('name', 'peinadora')->firstOrFail();

    Sanctum::actingAs($sistemas, ['*']);
    $this->putJson("/api/roles/{$role->id}", ['permissions' => ['clientes.ver']])->assertSuccessful();

    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');
    Sanctum::actingAs($peinadora, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '99999999',
    ])->assertForbidden();
});
