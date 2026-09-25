<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Services\PermisoTemporalService;
use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
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

    $departamento = UbigeoDepartamento::create(['codigo' => '25', 'nombre' => 'Ucayali']);
    $provincia = UbigeoProvincia::create(['ubigeo_departamento_id' => $departamento->id, 'codigo' => '01', 'nombre' => 'Coronel Portillo']);
    $this->distritoCasa = UbigeoDistrito::create(['ubigeo_provincia_id' => $provincia->id, 'codigo' => '05', 'nombre' => 'Manantay']);
    $this->distritoNegocio = UbigeoDistrito::create(['ubigeo_provincia_id' => $provincia->id, 'codigo' => '01', 'nombre' => 'Calleria']);
});

it('registra un cliente con ubigeo y coordenadas GPS de casa y de negocio, exponiendo distrito/provincia/departamento como texto derivado', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/clientes', [
        'nombre' => 'Flor', 'apellido' => 'Tuanama', 'tipo_documento' => 'dni', 'numero_documento' => '87654321',
        'direccion' => 'jr los pinos 123',
        'ubigeo_distrito_id' => $this->distritoCasa->id,
        'referencia' => 'frente al parque',
        'latitud' => -8.3791, 'longitud' => -74.5539,
        'direccion_negocio' => 'av central 456',
        'ubigeo_distrito_negocio_id' => $this->distritoNegocio->id,
        'referencia_negocio' => 'al lado del mercado',
        'latitud_negocio' => -8.3800, 'longitud_negocio' => -74.5600,
    ])->assertCreated();

    expect($response->json('data.distrito'))->toBe('Manantay')
        ->and($response->json('data.provincia'))->toBe('Coronel Portillo')
        ->and($response->json('data.departamento'))->toBe('Ucayali')
        ->and($response->json('data.distrito_negocio'))->toBe('Calleria')
        ->and((float) $response->json('data.latitud'))->toBe(-8.3791)
        ->and((float) $response->json('data.latitud_negocio'))->toBe(-8.38);

    $cliente = Cliente::find($response->json('data.id'));
    expect($cliente->ubigeo_distrito_id)->toBe($this->distritoCasa->id)
        ->and($cliente->ubigeo_distrito_negocio_id)->toBe($this->distritoNegocio->id);
});

it('rejects a ubigeo_distrito_id that does not exist', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Flor', 'apellido' => 'Tuanama', 'tipo_documento' => 'dni', 'numero_documento' => '87654322',
        'ubigeo_distrito_id' => 999999,
    ])->assertUnprocessable()->assertJsonValidationErrors('ubigeo_distrito_id');
});

it('actualiza el ubigeo del cliente', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create([
        'ubigeo_distrito_id' => $this->distritoCasa->id, 'asesor_id' => $this->asesor->id,
    ]);
    $admin = User::factory()->forEmpresa($this->empresa)->create();
    $admin->assignRole('administrador_general');
    app(PermisoTemporalService::class)->conceder($admin, $cliente, 'Prueba de edición temporal');

    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->putJson("/api/clientes/{$cliente->id}", [
        'nombre' => $cliente->nombre, 'apellido' => $cliente->apellido,
        'tipo_documento' => $cliente->tipo_documento, 'numero_documento' => $cliente->numero_documento,
        'ubigeo_distrito_id' => $this->distritoNegocio->id,
    ])->assertSuccessful();

    expect($response->json('data.distrito'))->toBe('Calleria');
});
