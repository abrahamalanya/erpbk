<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\FichaSocioeconomica;
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

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->cliente = Cliente::factory()->asignadoA($this->asesor)->create();
});

it('creates the ficha with its familiares on first PUT', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->putJson("/api/clientes/{$this->cliente->id}/ficha-socioeconomica", [
        'grado_instruccion' => 'universitario',
        'profesion' => 'contadora',
        'ing_conyuge' => 1500,
        'ing_renta1' => 500,
        'egp_alimentacion' => 400,
        'egn_alquiler' => 300,
        'viv_tenencia' => 'propia',
        'viv_redes_servicio' => ['luz_electrica', 'desague'],
        'familiares' => [
            ['nombres' => 'Juan Silva', 'edad' => 12, 'parentesco' => 'hijo'],
            ['nombres' => 'Ana Perez', 'edad' => 40, 'parentesco' => 'esposa', 'ocupacion' => 'comerciante'],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('data.grado_instruccion', 'universitario')
        ->assertJsonPath('data.total_ingresos', '2000.00')
        ->assertJsonPath('data.total_egresos_personales', '400.00')
        ->assertJsonPath('data.total_egresos_negocio', '300.00')
        ->assertJsonPath('data.total_neto', '1300.00')
        ->assertJsonCount(2, 'data.familiares');

    $ficha = FichaSocioeconomica::query()->where('cliente_id', $this->cliente->id)->first();
    expect($ficha)->not->toBeNull()
        ->and($ficha->empresa_id)->toBe($this->empresa->id)
        ->and($ficha->viv_redes_servicio)->toBe(['luz_electrica', 'desague']);
});

it('replaces the familiares list on a subsequent PUT', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->putJson("/api/clientes/{$this->cliente->id}/ficha-socioeconomica", [
        'familiares' => [['nombres' => 'Primero'], ['nombres' => 'Segundo']],
    ])->assertSuccessful()->assertJsonCount(2, 'data.familiares');

    $this->putJson("/api/clientes/{$this->cliente->id}/ficha-socioeconomica", [
        'familiares' => [['nombres' => 'Unico']],
    ])->assertSuccessful()->assertJsonCount(1, 'data.familiares')
        ->assertJsonPath('data.familiares.0.nombres', 'Unico');

    expect(FichaSocioeconomica::query()->where('cliente_id', $this->cliente->id)->count())->toBe(1);
});

it('exposes the computed edad from fecha_nacimiento on the cliente', function () {
    $this->cliente->update(['fecha_nacimiento' => now()->subYears(37)->subMonths(2)->toDateString()]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson("/api/clientes/{$this->cliente->id}")
        ->assertOk()
        ->assertJsonPath('data.edad', 37);
});

it('returns the ficha on GET, or null when there is none', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson("/api/clientes/{$this->cliente->id}/ficha-socioeconomica")
        ->assertSuccessful()
        ->assertJsonPath('data', null);

    $this->putJson("/api/clientes/{$this->cliente->id}/ficha-socioeconomica", ['profesion' => 'ingeniera'])
        ->assertSuccessful();

    $this->getJson("/api/clientes/{$this->cliente->id}/ficha-socioeconomica")
        ->assertSuccessful()
        ->assertJsonPath('data.profesion', 'ingeniera');
});

it('denies a role without clientes.editar from saving the ficha', function () {
    // secretaria tiene clientes.ver / clientes.crear pero no clientes.editar.
    $secretaria = User::factory()->forAgencia($this->agencia)->create();
    $secretaria->assignRole('secretaria');

    Sanctum::actingAs($secretaria, ['*']);
    $this->putJson("/api/clientes/{$this->cliente->id}/ficha-socioeconomica", ['profesion' => 'x'])
        ->assertForbidden();
});

it('does not let an asesor touch the ficha of a cliente outside their scope', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $clienteAjeno = Cliente::factory()->asignadoA($otroAsesor)->create();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->putJson("/api/clientes/{$clienteAjeno->id}/ficha-socioeconomica", ['profesion' => 'x'])
        ->assertForbidden();
});
