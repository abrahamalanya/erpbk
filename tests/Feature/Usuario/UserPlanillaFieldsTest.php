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

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    Sanctum::actingAs($this->admin, ['*']);
});

function datosUsuarioPlanilla(array $extra = []): array
{
    return [
        'nombre' => 'Norma',
        'apellido' => 'Quispe Quicaña',
        'dni' => '41586355',
        'email' => 'norma.quispe@test.com',
        'roles' => ['supervisor'],
        'agencia_id' => test()->agencia->id,
        ...$extra,
    ];
}

it('stores the planilla option with its régimen de pensión and seguro', function () {
    $id = $this->postJson('/api/usuarios', datosUsuarioPlanilla([
        'en_planilla' => true, 'sistema_pension' => 'afp', 'seguro_salud' => 'sis',
    ]))->assertCreated()->json('data.id');

    $user = User::query()->findOrFail($id);

    expect($user->en_planilla)->toBeTrue()
        ->and($user->sistema_pension)->toBe('afp')
        ->and($user->seguro_salud)->toBe('sis');
});

it('accepts the planilla option as the "1"/"0" strings a multipart form sends', function () {
    $id = $this->post('/api/usuarios', datosUsuarioPlanilla([
        'en_planilla' => '1', 'sistema_pension' => 'onp', 'seguro_salud' => 'essalud',
    ]), ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    expect(User::query()->findOrFail($id)->en_planilla)->toBeTrue();

    $this->post("/api/usuarios/{$id}", ['_method' => 'PUT', 'en_planilla' => '0'], ['Accept' => 'application/json'])
        ->assertSuccessful();

    $user = User::query()->findOrFail($id);
    expect($user->en_planilla)->toBeFalse()
        ->and($user->sistema_pension)->toBe('onp');

    $this->post('/api/usuarios', datosUsuarioPlanilla(['dni' => '41586356', 'email' => 'otro@test.com', 'en_planilla' => '1']), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sistema_pension', 'seguro_salud']);
});

it('defaults to not in planilla', function () {
    $id = $this->postJson('/api/usuarios', datosUsuarioPlanilla())->assertCreated()->json('data.id');

    expect(User::query()->findOrFail($id)->en_planilla)->toBeFalse();
});

it('requires the régimen and seguro when the usuario goes in planilla', function () {
    $this->postJson('/api/usuarios', datosUsuarioPlanilla(['en_planilla' => true]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sistema_pension', 'seguro_salud']);

    $this->postJson('/api/usuarios', datosUsuarioPlanilla([
        'en_planilla' => true, 'sistema_pension' => 'privada', 'seguro_salud' => 'eps',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['sistema_pension', 'seguro_salud']);
});

it('updates the planilla option', function () {
    $user = User::factory()->forAgencia($this->agencia)->create();
    $user->assignRole('asesor');

    $this->putJson("/api/usuarios/{$user->id}", ['en_planilla' => true, 'sistema_pension' => 'onp', 'seguro_salud' => 'essalud'])
        ->assertSuccessful();

    expect($user->fresh()->en_planilla)->toBeTrue()
        ->and($user->fresh()->sistema_pension)->toBe('onp');
});

it('filters the usuarios listado by en_planilla', function () {
    $enPlanilla = User::factory()->forAgencia($this->agencia)->create(['en_planilla' => true, 'sistema_pension' => 'onp', 'seguro_salud' => 'essalud']);
    $enPlanilla->assignRole('asesor');
    $fuera = User::factory()->forAgencia($this->agencia)->create();
    $fuera->assignRole('asesor');

    $ids = collect($this->getJson('/api/usuarios?en_planilla=1')->assertSuccessful()->json('data.data') ?? $this->getJson('/api/usuarios?en_planilla=1')->json('data'))
        ->pluck('id');

    expect($ids)->toContain($enPlanilla->id)
        ->and($ids)->not->toContain($fuera->id);
});
