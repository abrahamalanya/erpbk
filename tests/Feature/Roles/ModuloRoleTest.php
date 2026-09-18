<?php

use App\Modules\Sistemas\Models\Role;
use App\Modules\Sistemas\Services\ModuloService;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('seeds each rol with its default módulos', function () {
    expect(Role::where('name', 'administrador_general')->firstOrFail()->modulos()->pluck('key')->sort()->values()->all())
        ->toBe(['cajas', 'diario', 'hipotecario', 'prendario', 'solicitudes', 'vehicular']);

    expect(Role::where('name', 'asesor')->firstOrFail()->modulos()->pluck('key')->sort()->values()->all())
        ->toBe(['diario', 'hipotecario', 'prendario', 'vehicular']);

    expect(Role::where('name', 'peinadora')->firstOrFail()->modulos()->pluck('key')->all())->toBe([]);
});

it('gives sistemas every módulo regardless of role_modulo config', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');

    expect(app(ModuloService::class)->modulosEfectivos($sistemas))->toEqualCanonicalizing([
        'prendario', 'diario', 'hipotecario', 'vehicular', 'solicitudes', 'cajas',
    ]);
});

it('falls back to the role default when the user has no override', function () {
    $asesor = User::factory()->create();
    $asesor->assignRole('asesor');

    expect(app(ModuloService::class)->modulosEfectivos($asesor))
        ->toEqualCanonicalizing(['prendario', 'diario', 'hipotecario', 'vehicular']);
});

it('lets a user override replace the role default entirely', function () {
    $asesor = User::factory()->create();
    $asesor->assignRole('asesor');

    app(ModuloService::class)->asignar($asesor, ['prendario', 'cajas']);

    // "cajas" isn't in asesor's role default, but an explicit user override
    // replaces the default outright rather than narrowing it.
    expect(app(ModuloService::class)->modulosEfectivos($asesor))
        ->toEqualCanonicalizing(['prendario', 'cajas']);
});

it('lists the módulo catalog for sistemas only', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->getJson('/api/modulos')->assertSuccessful()->assertJsonCount(6, 'data');

    $admin = User::factory()->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->getJson('/api/modulos')->assertForbidden();
});

it('lets sistemas update a rol default módulos alongside its permissions', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $role = Role::where('name', 'asesor')->firstOrFail();

    $this->putJson("/api/roles/{$role->id}", [
        'permissions' => $role->permissions->pluck('name')->all(),
        'modulos' => ['prendario'],
    ])->assertSuccessful();

    expect($role->fresh()->modulos->pluck('key')->all())->toBe(['prendario']);

    $asesorSinOverride = User::factory()->create();
    $asesorSinOverride->assignRole('asesor');

    expect(app(ModuloService::class)->modulosEfectivos($asesorSinOverride))->toBe(['prendario']);
});

it('leaves a rol\'s módulos untouched when the request omits the field', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $role = Role::where('name', 'asesor')->firstOrFail();
    $before = $role->modulos()->pluck('key')->sort()->values()->all();

    $this->putJson("/api/roles/{$role->id}", ['permissions' => $role->permissions->pluck('name')->all()])
        ->assertSuccessful();

    expect($role->fresh()->modulos->pluck('key')->sort()->values()->all())->toBe($before);
});
