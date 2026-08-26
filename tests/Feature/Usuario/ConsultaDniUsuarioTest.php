<?php

use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    config(['services.consultasperu.token' => 'test-token']);

    $this->sistemas = User::factory()->create();
    $this->sistemas->assignRole('sistemas');
});

it('returns the mapped nombre/apellido on a successful lookup', function () {
    Http::fake([
        'api.consultasperu.com/*' => Http::response([
            'success' => true,
            'data' => [
                'number' => '12345678',
                'name' => 'Juan',
                'surname' => 'Perez Gomez',
                'address' => 'Av. Siempre Viva 123',
            ],
        ], 200),
    ]);

    Sanctum::actingAs($this->sistemas, ['*']);

    $response = $this->getJson('/api/usuarios/consultar-dni/12345678')->assertSuccessful();

    expect($response->json('data.nombre'))->toBe('Juan')
        ->and($response->json('data.apellido'))->toBe('Perez Gomez');
});

it('returns a clear error when the DNI is not found', function () {
    Http::fake([
        'api.consultasperu.com/*' => Http::response(['success' => false, 'message' => 'No data found'], 404),
    ]);

    Sanctum::actingAs($this->sistemas, ['*']);

    $this->getJson('/api/usuarios/consultar-dni/12345678')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se encontró información para este DNI.');
});

it('denies a role without usuarios.crear from consulting a DNI', function () {
    Http::fake();

    $asesor = User::factory()->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->getJson('/api/usuarios/consultar-dni/12345678')->assertForbidden();

    Http::assertNothingSent();
});
