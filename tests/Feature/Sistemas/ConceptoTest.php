<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->sistemas = User::factory()->create();
    $this->sistemas->assignRole('sistemas');

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
});

it('lets sistemas create a concepto for a given empresa', function () {
    Sanctum::actingAs($this->sistemas, ['*']);

    $this->postJson('/api/conceptos', ['empresa_id' => $this->empresa->id, 'tipo' => 'gasto', 'nombre' => 'Útiles de oficina'])
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'gasto')
        ->assertJsonPath('data.nombre', 'Útiles de oficina');

    expect(Concepto::query()->where('empresa_id', $this->empresa->id)->where('nombre', 'Útiles de oficina')->exists())->toBeTrue();
});

it('denies administrador_general from managing conceptos, but allows viewing them (needed to register a movimiento)', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson('/api/conceptos', ['empresa_id' => $this->empresa->id, 'tipo' => 'gasto', 'nombre' => 'Útiles de oficina'])
        ->assertForbidden();
    $this->getJson('/api/conceptos')->assertSuccessful();

    $concepto = Concepto::factory()->paraEmpresa($this->empresa)->create();
    $this->putJson("/api/conceptos/{$concepto->id}", ['activo' => false])->assertForbidden();
    $this->deleteJson("/api/conceptos/{$concepto->id}")->assertForbidden();
});

it('denies an asesor from managing conceptos but allows viewing them', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/conceptos', ['empresa_id' => $this->empresa->id, 'tipo' => 'gasto', 'nombre' => 'Útiles de oficina'])->assertForbidden();
    $this->getJson('/api/conceptos')->assertSuccessful();
});

it('rejects a duplicate concepto name for the same empresa and tipo', function () {
    Sanctum::actingAs($this->sistemas, ['*']);
    Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto', 'nombre' => 'Alquiler']);

    $this->postJson('/api/conceptos', ['empresa_id' => $this->empresa->id, 'tipo' => 'gasto', 'nombre' => 'Alquiler'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nombre');
});

it('allows the same nombre for a different tipo', function () {
    Sanctum::actingAs($this->sistemas, ['*']);
    Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto', 'nombre' => 'Comisiones']);

    $this->postJson('/api/conceptos', ['empresa_id' => $this->empresa->id, 'tipo' => 'ingreso', 'nombre' => 'Comisiones'])
        ->assertCreated();
});

it('allows the same nombre in a different empresa', function () {
    Sanctum::actingAs($this->sistemas, ['*']);
    $otraEmpresa = Empresa::factory()->create();
    Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto', 'nombre' => 'Alquiler']);

    $this->postJson('/api/conceptos', ['empresa_id' => $otraEmpresa->id, 'tipo' => 'gasto', 'nombre' => 'Alquiler'])
        ->assertCreated();
});

it('does not leak conceptos from another empresa to a regular tenant user', function () {
    $otraEmpresa = Empresa::factory()->create();
    Concepto::factory()->paraEmpresa($otraEmpresa)->create(['nombre' => 'De otra empresa']);

    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->getJson('/api/conceptos')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('lets sistemas filter the catalog by empresa', function () {
    $otraEmpresa = Empresa::factory()->create();
    Concepto::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'De la empresa']);
    Concepto::factory()->paraEmpresa($otraEmpresa)->create(['nombre' => 'De otra empresa']);

    Sanctum::actingAs($this->sistemas, ['*']);

    $this->getJson('/api/conceptos')->assertSuccessful()->assertJsonCount(2, 'data');
    $this->getJson("/api/conceptos?empresa_id={$this->empresa->id}")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'De la empresa');
});

it('only lists active conceptos by default', function () {
    Sanctum::actingAs($this->sistemas, ['*']);
    Concepto::factory()->paraEmpresa($this->empresa)->create(['activo' => true]);
    Concepto::factory()->paraEmpresa($this->empresa)->create(['activo' => false]);

    $this->getJson("/api/conceptos?empresa_id={$this->empresa->id}")->assertSuccessful()->assertJsonCount(1, 'data');
    $this->getJson("/api/conceptos?empresa_id={$this->empresa->id}&con_inactivos=1")
        ->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

it('deactivates a concepto instead of requiring deletion', function () {
    Sanctum::actingAs($this->sistemas, ['*']);
    $concepto = Concepto::factory()->paraEmpresa($this->empresa)->create(['activo' => true]);

    $this->putJson("/api/conceptos/{$concepto->id}", ['activo' => false])
        ->assertSuccessful()
        ->assertJsonPath('data.activo', false);
});

it('refuses to delete a concepto already used by a caja movimiento', function () {
    Sanctum::actingAs($this->sistemas, ['*']);
    $concepto = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto']);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    Sanctum::actingAs($this->sistemas, ['*']);
    $this->deleteJson("/api/conceptos/{$concepto->id}")->assertSuccessful();

    $concepto = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'ingreso']);
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/movimientos', ['tipo' => 'ingreso', 'concepto_id' => $concepto->id, 'monto' => 100])->assertCreated();

    Sanctum::actingAs($this->sistemas, ['*']);
    $this->deleteJson("/api/conceptos/{$concepto->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se puede eliminar un concepto que ya tiene movimientos registrados. Desactívalo en su lugar.');
});
