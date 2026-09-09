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
    $this->agencia1 = Agencia::factory()->for($this->empresa)->create();
    $this->agencia2 = Agencia::factory()->for($this->empresa)->create();

    $this->admin = User::factory()->forAgencia($this->agencia1)->create();
    $this->admin->assignRole('administrador_general');

    Sanctum::actingAs($this->admin, ['*']);
});

it('filters clientes by estado', function () {
    Cliente::factory()->forAgencia($this->agencia1)->create(['estado' => 'activo']);
    Cliente::factory()->forAgencia($this->agencia1)->create(['estado' => 'inactivo']);

    $response = $this->getJson('/api/clientes?estado=inactivo')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.estado'))->toBe('inactivo');
});

it('filters clientes by tipo_documento', function () {
    Cliente::factory()->forAgencia($this->agencia1)->create(['tipo_documento' => 'dni']);
    Cliente::factory()->forAgencia($this->agencia1)->create(['tipo_documento' => 'ce']);

    $response = $this->getJson('/api/clientes?tipo_documento=ce')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.tipo_documento'))->toBe('ce');
});

it('filters clientes by agencia_id', function () {
    Cliente::factory()->forAgencia($this->agencia1)->create();
    Cliente::factory()->forAgencia($this->agencia2)->create();

    $response = $this->getJson("/api/clientes?agencia_id={$this->agencia2->id}")->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.agencia_id'))->toBe($this->agencia2->id);
});

it('combines q with the estado filter', function () {
    Cliente::factory()->forAgencia($this->agencia1)->create(['nombre' => 'Carmen', 'estado' => 'activo']);
    Cliente::factory()->forAgencia($this->agencia1)->create(['nombre' => 'Carmen', 'estado' => 'inactivo']);

    $response = $this->getJson('/api/clientes?q=Carmen&estado=activo')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.estado'))->toBe('activo');
});
