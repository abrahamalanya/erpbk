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

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
});

it('filters clientes by nombre, apellido or numero_documento with the q parameter', function () {
    Cliente::factory()->asignadoA($this->asesor)->create(['nombre' => 'Marisol', 'apellido' => 'Quispe', 'numero_documento' => '44556677']);
    Cliente::factory()->asignadoA($this->asesor)->create(['nombre' => 'Julio', 'apellido' => 'Marín', 'numero_documento' => '11223344']);
    Cliente::factory()->asignadoA($this->asesor)->create(['nombre' => 'Rosa', 'apellido' => 'Torres', 'numero_documento' => '99887766']);

    Sanctum::actingAs($this->asesor, ['*']);

    $porNombre = $this->getJson('/api/clientes?q=Marisol')->assertSuccessful();
    expect($porNombre->json('data.data'))->toHaveCount(1)
        ->and($porNombre->json('data.data.0.nombre'))->toBe('Marisol');

    $porApellido = $this->getJson('/api/clientes?q=Marín')->assertSuccessful();
    expect($porApellido->json('data.data'))->toHaveCount(1)
        ->and($porApellido->json('data.data.0.apellido'))->toBe('Marín');

    $porDocumento = $this->getJson('/api/clientes?q=99887766')->assertSuccessful();
    expect($porDocumento->json('data.data'))->toHaveCount(1)
        ->and($porDocumento->json('data.data.0.numero_documento'))->toBe('99887766');
});

it('keeps the hierarchy scope while searching', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Cliente::factory()->asignadoA($this->asesor)->create(['nombre' => 'Ana', 'apellido' => 'Lopez']);
    Cliente::factory()->asignadoA($otroAsesor)->create(['nombre' => 'Ana', 'apellido' => 'Ramirez']);

    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->getJson('/api/clientes?q=Ana')->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.apellido'))->toBe('Lopez');
});

it('paginates and honours the per_page parameter', function () {
    Cliente::factory()->count(20)->asignadoA($this->asesor)->create();

    Sanctum::actingAs($this->asesor, ['*']);

    $porDefecto = $this->getJson('/api/clientes')->assertSuccessful();
    expect($porDefecto->json('data.data'))->toHaveCount(15)
        ->and($porDefecto->json('data.total'))->toBe(20);

    $ampliado = $this->getJson('/api/clientes?per_page=50')->assertSuccessful();
    expect($ampliado->json('data.data'))->toHaveCount(20);
});

it('caps per_page at 100', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->getJson('/api/clientes?per_page=5000')->assertSuccessful();
    expect($response->json('data.per_page'))->toBe(100);
});
