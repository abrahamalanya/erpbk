<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoHipotecario\Models\Inmueble;
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

it('filters inmuebles by free-text q across partida and direccion', function () {
    $predio = Inmueble::factory()->paraCliente($this->cliente1)->create([
        'partida_registral' => 'P11223344',
        'direccion' => 'av los pinos 123',
    ]);
    Inmueble::factory()->paraCliente($this->cliente1)->create([
        'partida_registral' => 'P99887766',
        'direccion' => 'jr las flores 45',
    ]);

    $porPartida = $this->getJson('/api/inmuebles?q=P11223344')->assertSuccessful();
    expect($porPartida->json('data.data'))->toHaveCount(1)
        ->and($porPartida->json('data.data.0.id'))->toBe($predio->id);

    $porDireccion = $this->getJson('/api/inmuebles?q=los pinos')->assertSuccessful();
    expect($porDireccion->json('data.data'))->toHaveCount(1)
        ->and($porDireccion->json('data.data.0.id'))->toBe($predio->id);
});

it('filters inmuebles by tipo_inmueble', function () {
    Inmueble::factory()->paraCliente($this->cliente1)->create(['tipo_inmueble' => 'Casa']);
    Inmueble::factory()->paraCliente($this->cliente1)->create(['tipo_inmueble' => 'Terreno']);

    $response = $this->getJson('/api/inmuebles?tipo_inmueble=terreno')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.tipo_inmueble'))->toBe('Terreno');
});

it('filters inmuebles by estado', function () {
    Inmueble::factory()->paraCliente($this->cliente1)->create(['estado' => 'en_garantia']);
    Inmueble::factory()->paraCliente($this->cliente1)->create(['estado' => 'disponible_venta']);

    $response = $this->getJson('/api/inmuebles?estado=disponible_venta')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.estado'))->toBe('disponible_venta');
});

it('filters inmuebles by con_gravamen', function () {
    Inmueble::factory()->paraCliente($this->cliente1)->create(['con_gravamen' => true]);
    Inmueble::factory()->paraCliente($this->cliente1)->create(['con_gravamen' => false]);

    $conGravamen = $this->getJson('/api/inmuebles?con_gravamen=1')->assertSuccessful();
    expect($conGravamen->json('data.data'))->toHaveCount(1)
        ->and($conGravamen->json('data.data.0.con_gravamen'))->toBeTrue();
});

it('filters inmuebles by agencia_id', function () {
    Inmueble::factory()->paraCliente($this->cliente1)->create();
    Inmueble::factory()->paraCliente($this->cliente2)->create();

    $response = $this->getJson("/api/inmuebles?agencia_id={$this->agencia2->id}")->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.agencia_id'))->toBe($this->agencia2->id);
});
