<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Ubicacion\Models\UbicacionAsesorHistorial;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
});

it('allows an asesor to register their own location', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/ubicaciones-asesores', [
        'latitud' => -8.379, 'longitud' => -74.553, 'precision_metros' => 10,
    ])->assertSuccessful();

    $this->assertDatabaseHas('ubicaciones_asesores', [
        'user_id' => $asesor->id,
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
    ]);
});

it('overwrites the previous location instead of keeping a history', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/ubicaciones-asesores', ['latitud' => -8.0, 'longitud' => -74.0])->assertSuccessful();
    $this->postJson('/api/ubicaciones-asesores', ['latitud' => -8.5, 'longitud' => -74.5])->assertSuccessful();

    expect(UbicacionAsesor::query()->where('user_id', $asesor->id)->count())->toBe(1);
});

it('appends to the history table without overwriting previous points', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/ubicaciones-asesores', [
        'latitud' => -8.0, 'longitud' => -74.0, 'capturado_en' => now()->subMinutes(10)->toISOString(),
    ])->assertSuccessful();
    $this->postJson('/api/ubicaciones-asesores', [
        'latitud' => -8.5, 'longitud' => -74.5, 'velocidad_ms' => 3.2, 'capturado_en' => now()->toISOString(),
    ])->assertSuccessful();

    expect(UbicacionAsesorHistorial::query()->where('user_id', $asesor->id)->count())->toBe(2)
        ->and(UbicacionAsesor::query()->where('user_id', $asesor->id)->count())->toBe(1);
});

it('does not append an out-of-order ping to the history', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/ubicaciones-asesores', [
        'latitud' => -8.0, 'longitud' => -74.0, 'capturado_en' => now()->toISOString(),
    ])->assertSuccessful();
    $this->postJson('/api/ubicaciones-asesores', [
        'latitud' => -8.5, 'longitud' => -74.5, 'capturado_en' => now()->subMinutes(30)->toISOString(),
    ])->assertSuccessful();

    expect(UbicacionAsesorHistorial::query()->where('user_id', $asesor->id)->count())->toBe(1);
});

it('denies a non-asesor from registering a location', function () {
    $secretaria = User::factory()->forAgencia($this->agencia)->create();
    $secretaria->assignRole('secretaria');
    Sanctum::actingAs($secretaria, ['*']);

    $this->postJson('/api/ubicaciones-asesores', ['latitud' => -8.379, 'longitud' => -74.553])
        ->assertForbidden();
});

it('denies an asesor from viewing the live map', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->getJson('/api/ubicaciones-asesores')->assertForbidden();
});

it('scopes visible locations to administrador_agencia to their own agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();

    $asesorPropio = User::factory()->forAgencia($this->agencia)->create();
    $asesorPropio->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $asesorPropio->id, 'latitud' => -8.0, 'longitud' => -74.0,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    $asesorOtraAgencia = User::factory()->forAgencia($otraAgencia)->create();
    $asesorOtraAgencia->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $asesorOtraAgencia->id, 'latitud' => -8.1, 'longitud' => -74.1,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $otraAgencia->id,
    ]);

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $response = $this->getJson('/api/ubicaciones-asesores')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.asesor_id'))->toBe($asesorPropio->id);
});

it('scopes visible locations to a supervisor to their own subordinados', function () {
    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');

    $propio = User::factory()->forAgencia($this->agencia)->create(['supervisor_id' => $supervisor->id]);
    $propio->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $propio->id, 'latitud' => -8.0, 'longitud' => -74.0,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    $ajeno = User::factory()->forAgencia($this->agencia)->create();
    $ajeno->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $ajeno->id, 'latitud' => -8.1, 'longitud' => -74.1,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    Sanctum::actingAs($supervisor, ['*']);

    $response = $this->getJson('/api/ubicaciones-asesores')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.asesor_id'))->toBe($propio->id);
});

it('lets sistemas see locations across every empresa', function () {
    $otraEmpresa = Empresa::factory()->create();
    $otraAgencia = Agencia::factory()->for($otraEmpresa)->create();

    $asesorA = User::factory()->forAgencia($this->agencia)->create();
    $asesorA->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $asesorA->id, 'latitud' => -8.0, 'longitud' => -74.0,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    $asesorB = User::factory()->forAgencia($otraAgencia)->create();
    $asesorB->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $asesorB->id, 'latitud' => -8.1, 'longitud' => -74.1,
        'capturado_en' => now(), 'empresa_id' => $otraEmpresa->id, 'agencia_id' => $otraAgencia->id,
    ]);

    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $response = $this->getJson('/api/ubicaciones-asesores')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(2);
});

it('returns the route history with points and a computed summary', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $hoy = now()->startOfDay();
    UbicacionAsesorHistorial::query()->create([
        'user_id' => $asesor->id, 'latitud' => -8.0, 'longitud' => -74.0,
        'capturado_en' => $hoy->copy()->addHours(8), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);
    UbicacionAsesorHistorial::query()->create([
        'user_id' => $asesor->id, 'latitud' => -8.01, 'longitud' => -74.01,
        'capturado_en' => $hoy->copy()->addHours(8)->addMinutes(5), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);
    UbicacionAsesorHistorial::query()->create([
        'user_id' => $asesor->id, 'latitud' => -8.02, 'longitud' => -74.02,
        'capturado_en' => $hoy->copy()->addHours(17), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    $response = $this->getJson('/api/ubicaciones-asesores/historial?'.http_build_query([
        'asesor_id' => $asesor->id,
        'desde' => $hoy->toDateString(),
        'hasta' => $hoy->toDateString(),
    ]))->assertSuccessful();

    expect($response->json('data.puntos'))->toHaveCount(3)
        ->and($response->json('data.resumen.distancia_m'))->toBeGreaterThan(0)
        ->and($response->json('data.resumen.pausas'))->toHaveCount(1);
});

it('denies access to the history of an asesor outside the actor scope', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $ajeno = User::factory()->forAgencia($otraAgencia)->create();
    $ajeno->assignRole('asesor');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->getJson('/api/ubicaciones-asesores/historial?'.http_build_query([
        'asesor_id' => $ajeno->id,
        'desde' => now()->toDateString(),
        'hasta' => now()->toDateString(),
    ]))->assertForbidden();
});

it('lists only the asesores visible to the actor for the history selector', function () {
    $propio = User::factory()->forAgencia($this->agencia)->create();
    $propio->assignRole('asesor');

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $ajeno = User::factory()->forAgencia($otraAgencia)->create();
    $ajeno->assignRole('asesor');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $response = $this->getJson('/api/ubicaciones-asesores/asesores-visibles')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($propio->id);
});
