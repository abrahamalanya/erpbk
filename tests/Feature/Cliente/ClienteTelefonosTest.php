<?php

use App\Modules\Cliente\Models\Cliente;
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

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    Sanctum::actingAs($this->admin, ['*']);

    $this->cliente = Cliente::factory()
        ->registradoPor($this->admin)
        ->forAgencia($this->agencia)
        ->create([
            'nombre' => 'Ana',
            'apellido' => 'Torres',
            'tipo_documento' => 'dni',
            'numero_documento' => '44555555',
        ]);
});

it('stores the whatsapp number and several telefonos on creation', function () {
    $response = $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '99999999',
        'agencia_id' => $this->agencia->id,
        'whatsapp' => '987654321',
        'telefonos' => ['111111111', '222222222'],
    ])->assertCreated();

    expect($response->json('data.whatsapp'))->toBe('987654321')
        ->and($response->json('data.telefonos.*.numero'))->toBe(['111111111', '222222222'])
        ->and($response->json('data.telefonos.*.orden'))->toBe([0, 1]);
});

it('replaces the whole telefonos list on update', function () {
    $this->cliente->telefonos()->create(['numero' => '555555555', 'orden' => 0]);

    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'telefonos' => ['666666666', '777777777'],
    ])->assertSuccessful();

    $numeros = $this->cliente->fresh(['telefonos'])->telefonos->pluck('numero')->all();

    expect($numeros)->toBe(['666666666', '777777777']);
});

it('does not touch the telefonos list when the field is not sent', function () {
    $this->cliente->telefonos()->create(['numero' => '555555555', 'orden' => 0]);

    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'nombre' => 'Ana María',
    ])->assertSuccessful();

    expect($this->cliente->fresh(['telefonos'])->telefonos->pluck('numero')->all())->toBe(['555555555']);
});

it('deletes every telefono when an empty list is sent', function () {
    $this->cliente->telefonos()->create(['numero' => '555555555', 'orden' => 0]);

    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'telefonos' => [],
    ])->assertSuccessful();

    expect($this->cliente->fresh(['telefonos'])->telefonos)->toHaveCount(0);
});

it('ignores a blank placeholder used to signal an emptied list over multipart', function () {
    $this->cliente->telefonos()->create(['numero' => '555555555', 'orden' => 0]);

    // multipart/FormData no puede mandar una clave con arreglo vacío: el
    // frontend/app mandan un string vacío como único elemento para que el
    // backend note que `telefonos` sí vino en el request.
    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'telefonos' => [''],
    ])->assertSuccessful();

    expect($this->cliente->fresh(['telefonos'])->telefonos)->toHaveCount(0);
});

it('rejects more than the allowed number of telefonos', function () {
    $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '99999998',
        'agencia_id' => $this->agencia->id,
        'telefonos' => ['1', '2', '3', '4', '5', '6'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('telefonos');
});

it('does not expose the telefonos of a cliente from another empresa', function () {
    $otraEmpresa = Empresa::factory()->create();
    $otraAgencia = Agencia::factory()->for($otraEmpresa)->create();
    $ajeno = Cliente::factory()->create(['agencia_id' => $otraAgencia->id]);
    $ajeno->telefonos()->create(['numero' => '999999999', 'orden' => 0]);

    $ids = collect($this->getJson('/api/clientes')->json('data.data'))
        ->flatMap(fn (array $fila) => $fila['telefonos'])
        ->pluck('numero')
        ->all();

    expect($ids)->not->toContain('999999999');
});
