<?php

use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->asesor = User::factory()->create();
    $this->asesor->assignRole('asesor');

    $this->amazonas = UbigeoDepartamento::create(['codigo' => '01', 'nombre' => 'Amazonas']);
    $this->chachapoyas = UbigeoProvincia::create([
        'ubigeo_departamento_id' => $this->amazonas->id, 'codigo' => '01', 'nombre' => 'Chachapoyas',
    ]);
    $this->chachapoyasDistrito = UbigeoDistrito::create([
        'ubigeo_provincia_id' => $this->chachapoyas->id, 'codigo' => '01', 'nombre' => 'Chachapoyas',
    ]);
    UbigeoDistrito::create(['ubigeo_provincia_id' => $this->chachapoyas->id, 'codigo' => '02', 'nombre' => 'Asuncion']);
});

it('lets any authenticated user list departamentos', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/ubigeo/departamentos')->assertSuccessful()->assertJsonCount(1, 'data');
});

it('lists provincias of a departamento', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson("/api/ubigeo/departamentos/{$this->amazonas->id}/provincias")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nombre', 'Chachapoyas');
});

it('lists distritos of a provincia', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson("/api/ubigeo/provincias/{$this->chachapoyas->id}/distritos")
        ->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

it('resolves the full chain of a distrito', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson("/api/ubigeo/distritos/{$this->chachapoyasDistrito->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.nombre', 'Chachapoyas')
        ->assertJsonPath('data.provincia.nombre', 'Chachapoyas')
        ->assertJsonPath('data.provincia.departamento.nombre', 'Amazonas');
});
