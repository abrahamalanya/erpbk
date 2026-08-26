<?php

use App\Modules\Sistemas\Models\ConfiguracionSistema;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('is publicly readable without authentication, defaulting to "umax" and no favicon', function () {
    $this->getJson('/api/configuracion')
        ->assertSuccessful()
        ->assertJsonPath('data.nombre_app', 'umax')
        ->assertJsonPath('data.favicon_url', null);
});

it('lets sistemas update the app name and favicon', function () {
    Storage::fake('public');

    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $response = $this->post('/api/configuracion', [
        '_method' => 'PUT',
        'nombre_app' => 'Credimas ERP',
        'favicon' => UploadedFile::fake()->image('favicon.png'),
    ], ['Accept' => 'application/json'])->assertSuccessful();

    expect($response->json('data.nombre_app'))->toBe('Credimas ERP')
        ->and($response->json('data.favicon_url'))->not->toBeNull();

    $configuracion = ConfiguracionSistema::actual();
    expect($configuracion->nombre_app)->toBe('Credimas ERP');
    Storage::disk('public')->assertExists($configuracion->favicon_path);
});

it('denies non-sistemas roles from updating the config', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->putJson('/api/configuracion', ['nombre_app' => 'Hackeado'])
        ->assertForbidden();
});

it('rejects a favicon in an unsupported format', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->post('/api/configuracion', [
        '_method' => 'PUT',
        'favicon' => UploadedFile::fake()->create('favicon.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('favicon');
});
