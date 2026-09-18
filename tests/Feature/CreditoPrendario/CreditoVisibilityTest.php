<?php

use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Services\ModuloService;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agenciaA = Agencia::factory()->for($this->empresa)->create();
    $this->agenciaB = Agencia::factory()->for($this->empresa)->create();
});

it('lets an asesor see only créditos they registered', function () {
    $asesorA = User::factory()->forAgencia($this->agenciaA)->create();
    $asesorA->assignRole('asesor');
    $asesorA2 = User::factory()->forAgencia($this->agenciaA)->create();
    $asesorA2->assignRole('asesor');

    $bien = Bien::factory()->forAgencia($this->agenciaA)->create();

    Credito::factory()->paraBien($bien)->create(['registrado_por' => $asesorA->id]);
    Credito::factory()->paraBien($bien)->create(['registrado_por' => $asesorA2->id]);

    Sanctum::actingAs($asesorA, ['*']);

    $this->getJson('/api/creditos-prendarios')->assertSuccessful()->assertJsonCount(1, 'data.data');
});

it('lets a supervisor see créditos registered by their subordinate asesores', function () {
    $supervisor = User::factory()->forAgencia($this->agenciaA)->create();
    $supervisor->assignRole('supervisor');
    $asesor = User::factory()->forAgencia($this->agenciaA)->create(['supervisor_id' => $supervisor->id]);
    $asesor->assignRole('asesor');

    $bien = Bien::factory()->forAgencia($this->agenciaA)->create();
    Credito::factory()->paraBien($bien)->create(['registrado_por' => $asesor->id]);

    Sanctum::actingAs($supervisor, ['*']);

    $this->getJson('/api/creditos-prendarios')->assertSuccessful()->assertJsonCount(1, 'data.data');
});

it('lets administrador_agencia see every crédito in their agencia only', function () {
    $bienA = Bien::factory()->forAgencia($this->agenciaA)->create();
    Credito::factory()->paraBien($bienA)->create();

    $bienB = Bien::factory()->forAgencia($this->agenciaB)->create();
    Credito::factory()->paraBien($bienB)->create();

    $adminAgenciaA = User::factory()->forAgencia($this->agenciaA)->create();
    $adminAgenciaA->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgenciaA, ['*']);

    $this->getJson('/api/creditos-prendarios')->assertSuccessful()->assertJsonCount(1, 'data.data');
});

it('lets administrador_general see créditos across every agencia of their empresa', function () {
    $bienA = Bien::factory()->forAgencia($this->agenciaA)->create();
    Credito::factory()->paraBien($bienA)->create();

    $bienB = Bien::factory()->forAgencia($this->agenciaB)->create();
    Credito::factory()->paraBien($bienB)->create();

    $adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $adminGeneral->assignRole('administrador_general');
    Sanctum::actingAs($adminGeneral, ['*']);

    $this->getJson('/api/creditos-prendarios')->assertSuccessful()->assertJsonCount(2, 'data.data');
});

it('hides créditos of a tipo outside the asesor\'s assigned módulos, even ones they registered before being restricted', function () {
    $asesor = User::factory()->forAgencia($this->agenciaA)->create();
    $asesor->assignRole('asesor');

    $bien = Bien::factory()->forAgencia($this->agenciaA)->create();
    $prendario = Credito::factory()->paraBien($bien)->create(['registrado_por' => $asesor->id]);
    $diario = Credito::factory()->diario()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agenciaA->id,
        'registrado_por' => $asesor->id,
    ]);

    app(ModuloService::class)->asignar($asesor, ['prendario']);

    Sanctum::actingAs($asesor, ['*']);

    $this->getJson('/api/creditos-prendarios')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.id', $prendario->id);

    $this->getJson("/api/creditos-prendarios/{$diario->id}")->assertForbidden();
});

it('keeps seeing every tipo while unrestricted (the default)', function () {
    $asesor = User::factory()->forAgencia($this->agenciaA)->create();
    $asesor->assignRole('asesor');

    $bien = Bien::factory()->forAgencia($this->agenciaA)->create();
    Credito::factory()->paraBien($bien)->create(['registrado_por' => $asesor->id]);
    Credito::factory()->diario()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agenciaA->id,
        'registrado_por' => $asesor->id,
    ]);

    Sanctum::actingAs($asesor, ['*']);

    $this->getJson('/api/creditos-prendarios')->assertSuccessful()->assertJsonCount(2, 'data.data');
});
