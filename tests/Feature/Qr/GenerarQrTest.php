<?php

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
});

it('generates a PNG QR for a user with role sistemas', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('sistemas');
    Sanctum::actingAs($user, ['*']);

    $response = $this->get('/api/qr/generar?url='.urlencode('https://umax.test'));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toBe('image/png');
    expect(strlen($response->getContent()))->toBeGreaterThan(0);
});

it('generates a PNG QR for a user with role administrador_general', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('administrador_general');
    Sanctum::actingAs($user, ['*']);

    $response = $this->get('/api/qr/generar?url='.urlencode('https://umax.test'));

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toBe('image/png');
});

it('forbids a user without sistemas or administrador_general role', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->get('/api/qr/generar?url='.urlencode('https://umax.test'))->assertForbidden();
});

it('forbids an administrador_agencia without sistemas or administrador_general', function () {
    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $this->get('/api/qr/generar?url='.urlencode('https://umax.test'))->assertForbidden();
});

it('rejects a missing or invalid url', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('sistemas');
    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/qr/generar')->assertUnprocessable();
    $this->getJson('/api/qr/generar?url=no-es-una-url')->assertUnprocessable();
});

it('requires authentication', function () {
    $this->getJson('/api/qr/generar?url='.urlencode('https://umax.test'))->assertUnauthorized();
});
