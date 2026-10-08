<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    config(['services.consultasperu.token' => 'test-token']);

    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();
    $this->asesor = User::factory()->forAgencia($agencia)->create();
    $this->asesor->assignRole('asesor');
});

it('permite registrar un cliente con RUC', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'IMPORTACIONES',
        'apellido' => 'DEL SUR S.A.C.',
        'tipo_documento' => 'ruc',
        'numero_documento' => '20601030013',
        'direccion' => 'Av. Javier Prado Este 4200',
    ])->assertCreated();

    $this->assertDatabaseHas('clientes', [
        'tipo_documento' => 'ruc',
        'numero_documento' => '20601030013',
    ]);
});

it('rechaza un RUC con el digito verificador incorrecto antes de guardarlo', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'IMPORTACIONES',
        'apellido' => 'DEL SUR S.A.C.',
        'tipo_documento' => 'ruc',
        'numero_documento' => '20123456789',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('numero_documento')
        ->assertJsonPath('errors.numero_documento.0', 'El RUC no es válido: el dígito verificador no coincide.');
});

it('rechaza un RUC con longitud incorrecta', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'IMPORTACIONES',
        'apellido' => 'DEL SUR S.A.C.',
        'tipo_documento' => 'ruc',
        'numero_documento' => '2060103001',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('numero_documento')
        ->assertJsonPath('errors.numero_documento.0', 'El ruc debe tener 11 caracteres.');
});

it('rechaza un DNI con longitud incorrecta', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Juan',
        'apellido' => 'Perez',
        'tipo_documento' => 'dni',
        'numero_documento' => '123456789',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('numero_documento')
        ->assertJsonPath('errors.numero_documento.0', 'El dni debe tener 8 caracteres.');
});

it('sigue aceptando el CE alfanumerico', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'John',
        'apellido' => 'Smith',
        'tipo_documento' => 'ce',
        'numero_documento' => 'X1234567A',
    ])->assertCreated();
});

it('rechaza un tipo de documento fuera del catalogo', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Juan',
        'apellido' => 'Perez',
        'tipo_documento' => 'licencia',
        'numero_documento' => 'Q12345678',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('tipo_documento');
});

it('devuelve la razon social al consultar un RUC', function () {
    Http::fake([
        'api.consultasperu.com/*' => Http::response([
            'success' => true,
            'data' => [
                'number' => '20601030013',
                'name' => 'IMPORTACIONES DEL SUR',
                'surname' => 'S.A.C.',
                'address' => 'Av. Javier Prado Este 4200',
                'district' => 'San Isidro',
                'province' => 'Lima',
                'department' => 'Lima',
                'status' => 'ACTIVO',
            ],
        ], 200),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->getJson('/api/clientes/consultar-ruc/20601030013')->assertSuccessful();

    expect($response->json('data'))->toBe([
        'numero_documento' => '20601030013',
        'nombre' => 'IMPORTACIONES DEL SUR',
        'apellido' => 'S.A.C.',
        'direccion' => 'Av. Javier Prado Este 4200, San Isidro, Lima, Lima',
        'estado' => 'ACTIVO',
    ]);

    Http::assertSent(fn ($request) => $request['type_document'] === 'ruc'
        && $request['document_number'] === '20601030013');
});

it('rechaza un RUC con digito verificador erroneo sin llamar a la API', function () {
    Http::fake();

    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/clientes/consultar-ruc/20123456789')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El RUC no es válido: el dígito verificador no coincide.');

    Http::assertNothingSent();
});

it('avisa cuando el RUC no existe', function () {
    Http::fake([
        'api.consultasperu.com/*' => Http::response(['success' => false, 'message' => 'No data found'], 404),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/clientes/consultar-ruc/20601030013')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se encontró información para este RUC.');
});

it('niega la consulta de RUC a un rol sin clientes.crear', function () {
    Http::fake();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    Sanctum::actingAs($supervisor, ['*']);

    $this->getJson('/api/clientes/consultar-ruc/20601030013')->assertForbidden();

    Http::assertNothingSent();
});
