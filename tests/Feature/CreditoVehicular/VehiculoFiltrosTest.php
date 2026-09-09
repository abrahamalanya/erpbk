<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia1 = Agencia::factory()->for($this->empresa)->create();
    $this->agencia2 = Agencia::factory()->for($this->empresa)->create();
    $this->cliente1 = Cliente::factory()->forAgencia($this->agencia1)->create();
    $this->cliente2 = Cliente::factory()->forAgencia($this->agencia2)->create();

    $this->admin = User::factory()->forAgencia($this->agencia1)->create();
    $this->admin->assignRole('administrador_general');

    Sanctum::actingAs($this->admin, ['*']);
});

it('filters vehiculos by free-text q across placa and marca', function () {
    $toyota = Vehiculo::factory()->paraCliente($this->cliente1)->create(['placa' => 'XYZ-987', 'marca' => 'toyota']);
    Vehiculo::factory()->paraCliente($this->cliente1)->create(['placa' => 'AAA-111', 'marca' => 'kia']);

    $porPlaca = $this->getJson('/api/vehiculos?q=XYZ-987')->assertSuccessful();
    expect($porPlaca->json('data.data'))->toHaveCount(1)
        ->and($porPlaca->json('data.data.0.id'))->toBe($toyota->id);

    $porMarca = $this->getJson('/api/vehiculos?q=toyota')->assertSuccessful();
    expect($porMarca->json('data.data'))->toHaveCount(1)
        ->and($porMarca->json('data.data.0.id'))->toBe($toyota->id);
});

it('filters vehiculos by estado', function () {
    Vehiculo::factory()->paraCliente($this->cliente1)->create(['estado' => 'en_garantia']);
    Vehiculo::factory()->paraCliente($this->cliente1)->create(['estado' => 'disponible_venta']);

    $response = $this->getJson('/api/vehiculos?estado=disponible_venta')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.estado'))->toBe('disponible_venta');
});

it('filters vehiculos by tiene_soat', function () {
    Vehiculo::factory()->paraCliente($this->cliente1)->create(['tiene_soat' => true]);
    Vehiculo::factory()->paraCliente($this->cliente1)->create(['tiene_soat' => false]);

    $conSoat = $this->getJson('/api/vehiculos?tiene_soat=1')->assertSuccessful();
    expect($conSoat->json('data.data'))->toHaveCount(1)
        ->and($conSoat->json('data.data.0.tiene_soat'))->toBeTrue();

    $sinSoat = $this->getJson('/api/vehiculos?tiene_soat=0')->assertSuccessful();
    expect($sinSoat->json('data.data'))->toHaveCount(1)
        ->and($sinSoat->json('data.data.0.tiene_soat'))->toBeFalse();
});

it('filters vehiculos by agencia_id', function () {
    Vehiculo::factory()->paraCliente($this->cliente1)->create();
    Vehiculo::factory()->paraCliente($this->cliente2)->create();

    $response = $this->getJson("/api/vehiculos?agencia_id={$this->agencia2->id}")->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.agencia_id'))->toBe($this->agencia2->id);
});
