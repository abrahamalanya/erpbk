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
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    Sanctum::actingAs($this->asesor, ['*']);
});

/**
 * @return array<string, mixed>
 */
function vehiculoPayload(array $overrides = []): array
{
    return array_merge([
        'placa' => 'ABC-123',
        'motor' => 'MTR-0001',
        'serie' => 'VIN-0001',
        'color' => 'rojo',
        'marca' => 'toyota',
        'propietario' => 'juan perez',
        'tiene_soat' => true,
        'dejo_llave' => true,
        'dejo_tarjeta_propiedad' => false,
        'valorizacion' => 15000,
        'puntaje' => 7,
    ], $overrides);
}

it('stores dejó llave y dejó tarjeta de propiedad when registering a vehículo', function () {
    $response = $this->postJson('/api/vehiculos', vehiculoPayload([
        'cliente_id' => $this->cliente->id,
        'dejo_llave' => true,
        'dejo_tarjeta_propiedad' => false,
    ]))->assertCreated();

    expect($response->json('data.dejo_llave'))->toBeTrue()
        ->and($response->json('data.dejo_tarjeta_propiedad'))->toBeFalse();

    $vehiculo = Vehiculo::query()->findOrFail($response->json('data.id'));
    expect($vehiculo->dejo_llave)->toBeTrue()
        ->and($vehiculo->dejo_tarjeta_propiedad)->toBeFalse();
});

it('requires dejó llave y dejó tarjeta de propiedad to register a vehículo', function () {
    $this->postJson('/api/vehiculos', vehiculoPayload([
        'cliente_id' => $this->cliente->id,
        'dejo_llave' => null,
        'dejo_tarjeta_propiedad' => null,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['dejo_llave', 'dejo_tarjeta_propiedad']);
});

it('updates dejó llave y dejó tarjeta de propiedad on an available vehículo', function () {
    $vehiculo = Vehiculo::factory()->paraCliente($this->cliente)->create([
        'dejo_llave' => false,
        'dejo_tarjeta_propiedad' => false,
    ]);

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson("/api/vehiculos/{$vehiculo->id}", vehiculoPayload([
        'dejo_llave' => true,
        'dejo_tarjeta_propiedad' => true,
    ]))->assertSuccessful();

    expect($vehiculo->fresh()->dejo_llave)->toBeTrue()
        ->and($vehiculo->fresh()->dejo_tarjeta_propiedad)->toBeTrue();
});
