<?php

use App\Modules\Cliente\Models\Cliente;
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
    $this->agencia1 = Agencia::factory()->for($this->empresa)->create();
    $this->agencia2 = Agencia::factory()->for($this->empresa)->create();
    $this->cliente1 = Cliente::factory()->forAgencia($this->agencia1)->create();
    $this->cliente2 = Cliente::factory()->forAgencia($this->agencia2)->create();

    $this->admin = User::factory()->forAgencia($this->agencia1)->create();
    $this->admin->assignRole('administrador_general');

    Sanctum::actingAs($this->admin, ['*']);
});

it('filters bienes by free-text q across nombre, marca and codigo', function () {
    $laptop = Bien::factory()->paraCliente($this->cliente1)->create(['nombre' => 'laptop lenovo', 'marca' => 'lenovo']);
    Bien::factory()->paraCliente($this->cliente1)->create(['nombre' => 'refrigeradora', 'marca' => 'lg']);

    $porNombre = $this->getJson('/api/bienes?q=laptop')->assertSuccessful();
    expect($porNombre->json('data.data'))->toHaveCount(1)
        ->and($porNombre->json('data.data.0.id'))->toBe($laptop->id);

    $porCodigo = $this->getJson('/api/bienes?q='.$laptop->fresh()->codigo)->assertSuccessful();
    expect($porCodigo->json('data.data'))->toHaveCount(1)
        ->and($porCodigo->json('data.data.0.id'))->toBe($laptop->id);
});

it('filters bienes by tipo', function () {
    Bien::factory()->paraCliente($this->cliente1)->create(['tipo' => 'electro']);
    Bien::factory()->paraCliente($this->cliente1)->varios()->create();

    $response = $this->getJson('/api/bienes?tipo=varios')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.tipo'))->toBe('varios');
});

it('filters bienes by estado', function () {
    Bien::factory()->paraCliente($this->cliente1)->create(['estado' => 'en_garantia']);
    Bien::factory()->paraCliente($this->cliente1)->create(['estado' => 'disponible_venta']);

    $response = $this->getJson('/api/bienes?estado=disponible_venta')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.estado'))->toBe('disponible_venta');
});

it('filters bienes by agencia_id', function () {
    Bien::factory()->paraCliente($this->cliente1)->create();
    Bien::factory()->paraCliente($this->cliente2)->create();

    $response = $this->getJson("/api/bienes?agencia_id={$this->agencia2->id}")->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.agencia_id'))->toBe($this->agencia2->id);
});
