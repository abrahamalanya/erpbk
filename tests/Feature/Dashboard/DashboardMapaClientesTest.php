<?php

use App\Modules\Cliente\Models\Cliente;
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

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $this->otroAsesor->assignRole('asesor');
});

it('lists only clientes with coordinates registered', function () {
    Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'latitud' => -8.379, 'longitud' => -74.5539,
    ]);
    Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'latitud' => null, 'longitud' => null,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/dashboard/mapa-clientes')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1);
});

it('excludes inactive clientes from the map', function () {
    Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'latitud' => -8.379, 'longitud' => -74.5539, 'estado' => 'inactivo',
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/dashboard/mapa-clientes')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(0);
});

it('flags a cliente without an active crédito', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'latitud' => -8.379, 'longitud' => -74.5539,
    ]);
    $bien = Bien::factory()->paraCliente($cliente)->create();
    Credito::factory()->paraBien($bien)->create(['estado' => 'liquidado']);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/mapa-clientes')->assertSuccessful()->json('data');

    expect($data[0]['tiene_credito_activo'])->toBeFalse()
        ->and($data[0]['fecha_vencimiento_credito'])->toBeNull();
});

it('shows the fecha de vencimiento of the most urgent activo/vencido crédito', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'latitud' => -8.379, 'longitud' => -74.5539,
    ]);
    $bien = Bien::factory()->paraCliente($cliente)->create();
    $bien2 = Bien::factory()->paraCliente($cliente)->create();

    Credito::factory()->paraBien($bien)->activo()->create(['fecha_vencimiento' => now()->addDays(20)->toDateString()]);
    $masUrgente = Credito::factory()->paraBien($bien2)->vencido(3)->create();

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/mapa-clientes')->assertSuccessful()->json('data');

    expect($data[0]['tiene_credito_activo'])->toBeTrue()
        ->and($data[0]['fecha_vencimiento_credito'])->toBe($masUrgente->fecha_vencimiento->toDateString());
});

it('only shows the asesor their own clientes on the map', function () {
    Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'latitud' => -8.379, 'longitud' => -74.5539,
    ]);
    Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->otroAsesor)->create([
        'latitud' => -8.38, 'longitud' => -74.55,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/mapa-clientes')->assertSuccessful()->json('data');

    expect($data)->toHaveCount(1);
});

it('denies a user without clientes.ver from viewing the map', function () {
    $sinRol = User::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($sinRol, ['*']);
    $this->getJson('/api/dashboard/mapa-clientes')->assertForbidden();
});
