<?php

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Tienda\Models\InteresArticulo;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');
    Sanctum::actingAs($this->admin, ['*']);
});

it('lists the intereses of the actor’s agencia only', function () {
    $bienPropio = Bien::factory()->forAgencia($this->agencia)->create();
    InteresArticulo::factory()->paraArticulo($bienPropio)->create(['nombre' => 'Cliente propio']);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $bienAjeno = Bien::factory()->forAgencia($otraAgencia)->create();
    InteresArticulo::factory()->paraArticulo($bienAjeno)->create(['nombre' => 'Cliente ajeno']);

    $response = $this->getJson('/api/tienda-solicitudes')->assertSuccessful();

    $nombres = collect($response->json('data.data'))->pluck('nombre');
    expect($nombres)->toContain('Cliente propio')->not->toContain('Cliente ajeno');
});

it('filters by pendientes (atendido_at null)', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    InteresArticulo::factory()->paraArticulo($bien)->create(['atendido_at' => now()]);
    InteresArticulo::factory()->paraArticulo($bien)->create(['atendido_at' => null]);

    $response = $this->getJson('/api/tienda-solicitudes?pendientes=1')->assertSuccessful();

    expect($response->json('data.total'))->toBe(1);
});

it('marks a solicitud as atendida', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    $interes = InteresArticulo::factory()->paraArticulo($bien)->create(['atendido_at' => null]);

    $this->postJson("/api/tienda-solicitudes/{$interes->id}/atender")
        ->assertSuccessful()
        ->assertJsonPath('data.atendido_at', fn ($value) => $value !== null);

    expect($interes->fresh()->atendido_at)->not->toBeNull();
});

it('deletes a solicitud', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    $interes = InteresArticulo::factory()->paraArticulo($bien)->create();

    $this->deleteJson("/api/tienda-solicitudes/{$interes->id}")->assertSuccessful();

    expect(InteresArticulo::find($interes->id))->toBeNull();
});

it('allows an asesor to view and atender solicitudes of their own agencia', function () {
    $bienPropio = Bien::factory()->forAgencia($this->agencia)->create();
    $interes = InteresArticulo::factory()->paraArticulo($bienPropio)->create(['atendido_at' => null]);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->getJson('/api/tienda-solicitudes')->assertSuccessful();
    $this->postJson("/api/tienda-solicitudes/{$interes->id}/atender")->assertSuccessful();
});

it('denies an asesor from atendiendo a solicitud of another agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $bienAjeno = Bien::factory()->forAgencia($otraAgencia)->create();
    $interes = InteresArticulo::factory()->paraArticulo($bienAjeno)->create();

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson("/api/tienda-solicitudes/{$interes->id}/atender")->assertForbidden();
});

it('denies a role without the permission (e.g. peinadora) from viewing solicitudes', function () {
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');
    Sanctum::actingAs($peinadora, ['*']);

    $this->getJson('/api/tienda-solicitudes')->assertForbidden();
});

it('denies an administrador_agencia from managing another agencia’s solicitud', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $bienAjeno = Bien::factory()->forAgencia($otraAgencia)->create();
    $interes = InteresArticulo::factory()->paraArticulo($bienAjeno)->create();

    $this->postJson("/api/tienda-solicitudes/{$interes->id}/atender")->assertForbidden();
});
