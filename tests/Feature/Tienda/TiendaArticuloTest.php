<?php

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Tienda\Models\InteresArticulo;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

it('lists bienes and vehículos en venta together in the unified feed', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);
    $vehiculo = Vehiculo::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 25000]);
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia']);
    Vehiculo::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia']);

    $data = $this->getJson('/api/tienda/articulos')->assertSuccessful()->json('data.data');

    $tipos = collect($data)->pluck('articulo_tipo')->sort()->values()->all();
    expect($data)->toHaveCount(2)
        ->and($tipos)->toBe(['bien', 'vehiculo']);

    $itemVehiculo = collect($data)->firstWhere('articulo_tipo', 'vehiculo');
    expect($itemVehiculo)->toHaveKey('placa')
        ->and($itemVehiculo['id'])->toBe($vehiculo->id)
        ->and($itemVehiculo)->not->toHaveKey('cliente_id')
        ->and($itemVehiculo)->not->toHaveKey('propietario');
});

it('filters the unified feed by tipo', function () {
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);
    Vehiculo::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);

    $data = $this->getJson('/api/tienda/articulos?tipo=vehiculo')->assertSuccessful()->json('data.data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['articulo_tipo'])->toBe('vehiculo');
});

it('shows a vehículo detail and rejects one not disponible_venta', function () {
    $vehiculo = Vehiculo::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);
    $enGarantia = Vehiculo::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia']);

    $this->getJson("/api/tienda/articulos/vehiculo/{$vehiculo->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $vehiculo->id)
        ->assertJsonPath('data.articulo_tipo', 'vehiculo');

    $this->getJson("/api/tienda/articulos/vehiculo/{$enGarantia->id}")->assertNotFound();
});

it('records interest in a vehículo and notifies the administrador_agencia', function () {
    $vehiculo = Vehiculo::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);

    $this->postJson("/api/tienda/articulos/vehiculo/{$vehiculo->id}/interes", [
        'nombre' => 'Ana Torres',
        'telefono' => '999111222',
    ])->assertCreated();

    expect(InteresArticulo::where('articulo_type', 'vehiculo')->where('articulo_id', $vehiculo->id)->exists())->toBeTrue();
    expect($this->adminAgencia->notifications()->count())->toBe(1);
});

it('rejects an unknown articulo tipo', function () {
    $this->getJson('/api/tienda/articulos/inmueble/1')->assertNotFound();
});
