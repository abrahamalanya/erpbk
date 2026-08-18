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
    $this->peinadora = User::factory()->forAgencia($agencia)->create();
    $this->peinadora->assignRole('peinadora');
});

it('returns the mapped nombre/apellido/direccion on a successful lookup', function () {
    Http::fake([
        'api.consultasperu.com/*' => Http::response([
            'success' => true,
            'data' => [
                'number' => '12345678',
                'name' => 'Juan',
                'surname' => 'Perez Gomez',
                'address' => 'Av. Siempre Viva 123',
                'district' => 'Miraflores',
                'province' => 'Lima',
                'department' => 'Lima',
            ],
        ], 200),
    ]);

    Sanctum::actingAs($this->peinadora, ['*']);

    $response = $this->getJson('/api/clientes/consultar-dni/12345678')->assertSuccessful();

    expect($response->json('data'))->toBe([
        'numero_documento' => '12345678',
        'nombre' => 'Juan',
        'apellido' => 'Perez Gomez',
        'direccion' => 'Av. Siempre Viva 123, Miraflores, Lima, Lima',
    ]);

    Http::assertSent(fn ($request) => $request['token'] === 'test-token'
        && $request['type_document'] === 'dni'
        && $request['document_number'] === '12345678');
});

it('returns a clear error when the DNI is not found', function () {
    Http::fake([
        'api.consultasperu.com/*' => Http::response(['success' => false, 'message' => 'No data found'], 404),
    ]);

    Sanctum::actingAs($this->peinadora, ['*']);

    $this->getJson('/api/clientes/consultar-dni/12345678')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se encontró información para este DNI.');
});

it('returns a clear error when the token is invalid', function () {
    Http::fake([
        'api.consultasperu.com/*' => Http::response(['success' => false, 'message' => 'Invalid Token'], 401),
    ]);

    Sanctum::actingAs($this->peinadora, ['*']);

    $this->getJson('/api/clientes/consultar-dni/12345678')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Token de ConsultasPerú inválido. Revisa la configuración.');
});

it('rejects a malformed DNI without ever calling the external API', function () {
    Http::fake();

    Sanctum::actingAs($this->peinadora, ['*']);

    $this->getJson('/api/clientes/consultar-dni/123')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El DNI debe tener 8 dígitos.');

    Http::assertNothingSent();
});

it('denies a role without clientes.crear from consulting a DNI', function () {
    Http::fake();

    $supervisor = User::factory()->create();
    $supervisor->assignRole('supervisor');
    Sanctum::actingAs($supervisor, ['*']);

    $this->getJson('/api/clientes/consultar-dni/12345678')->assertForbidden();

    Http::assertNothingSent();
});
