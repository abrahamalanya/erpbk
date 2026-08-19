<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
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
});

it('lets an asesor edit a bien of their own cliente', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $cliente = Cliente::factory()->asignadoA($asesor)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create(['valorizacion' => 500]);
    Sanctum::actingAs($asesor, ['*']);

    $this->putJson("/api/bienes/{$bien->id}", [
        'tipo' => 'varios',
        'nombre' => 'Anillo actualizado',
        'valorizacion' => 650,
        'puntaje' => 6,
    ])->assertSuccessful()
        ->assertJsonPath('data.nombre', 'Anillo actualizado')
        ->assertJsonPath('data.valorizacion', '650.00');
});

it('denies an asesor from editing a bien of another asesor\'s cliente', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $clienteAjeno = Cliente::factory()->asignadoA($otroAsesor)->create();
    $bien = Bien::factory()->paraCliente($clienteAjeno)->create();
    Sanctum::actingAs($asesor, ['*']);

    $this->putJson("/api/bienes/{$bien->id}", [
        'tipo' => 'varios',
        'nombre' => 'Intento ajeno',
        'valorizacion' => 100,
        'puntaje' => 5,
    ])->assertForbidden();
});

it('denies editing without the bienes.editar permission', function () {
    $secretaria = User::factory()->forEmpresa($this->empresa)->create();
    $secretaria->assignRole('secretaria');
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create();
    Sanctum::actingAs($secretaria, ['*']);

    $this->putJson("/api/bienes/{$bien->id}", [
        'tipo' => 'varios',
        'nombre' => 'Sin permiso',
        'valorizacion' => 100,
        'puntaje' => 5,
    ])->assertForbidden();
});

it('denies editing a bien that is backing an active crédito', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $cliente = Cliente::factory()->asignadoA($asesor)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create(['valorizacion' => 500]);
    CreditoPrendario::factory()->paraBien($bien)->create(['estado' => 'activo']);
    Sanctum::actingAs($asesor, ['*']);

    $this->putJson("/api/bienes/{$bien->id}", [
        'tipo' => 'varios',
        'nombre' => 'No debería poder',
        'valorizacion' => 999,
        'puntaje' => 5,
    ])->assertUnprocessable();
});

it('allows editing a bien again once its crédito is liquidado', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $cliente = Cliente::factory()->asignadoA($asesor)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create(['valorizacion' => 500]);
    CreditoPrendario::factory()->paraBien($bien)->create(['estado' => 'liquidado']);
    Sanctum::actingAs($asesor, ['*']);

    $this->putJson("/api/bienes/{$bien->id}", [
        'tipo' => 'varios',
        'nombre' => 'Ahora sí',
        'valorizacion' => 600,
        'puntaje' => 6,
    ])->assertSuccessful();
});
