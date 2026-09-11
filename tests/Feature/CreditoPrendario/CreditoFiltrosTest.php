<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
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
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create(['tipo_credito' => 'prendario']);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create(['tipo_credito' => 'vehicular']);
    $this->cliente1 = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->cliente2 = Cliente::factory()->forAgencia($this->agencia)->create();

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_general');

    Sanctum::actingAs($this->admin, ['*']);
});

it('filters créditos by tipo_credito', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->activo()->create();
    Credito::factory()->paraBien($bien)->vehicular()->activo()->create();

    $response = $this->getJson('/api/creditos-prendarios?tipo_credito=vehicular')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.tipo_credito'))->toBe('vehicular');
});

it('filters créditos by cliente_id', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente1)->create();
    $bien2 = Bien::factory()->paraCliente($this->cliente2)->create();
    Credito::factory()->paraBien($bien1)->activo()->create();
    Credito::factory()->paraBien($bien2)->activo()->create();

    $response = $this->getJson("/api/creditos-prendarios?cliente_id={$this->cliente2->id}")->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.cliente_id'))->toBe($this->cliente2->id);
});

it('filters créditos by estado', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->activo()->create();
    Credito::factory()->paraBien($bien)->vencido()->create();

    $response = $this->getJson('/api/creditos-prendarios?estado=vencido')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.estado'))->toBe('vencido');
});

it('combines tipo_credito, cliente_id and estado filters', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente1)->create();
    $bien2 = Bien::factory()->paraCliente($this->cliente2)->create();
    Credito::factory()->paraBien($bien1)->vencido()->create();
    Credito::factory()->paraBien($bien2)->vencido()->create();
    Credito::factory()->paraBien($bien1)->activo()->create();

    $response = $this->getJson(
        "/api/creditos-prendarios?tipo_credito=prendario&cliente_id={$this->cliente1->id}&estado=vencido"
    )->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1);
});
