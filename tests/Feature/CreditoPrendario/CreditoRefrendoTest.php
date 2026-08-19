<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
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
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro']);
});

it('creates a new chained crédito on refrendo and marks the original as refrendado, generating only an adenda', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1, 'max_refrendos' => null,
    ]);

    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id]);

    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson("/api/creditos-prendarios/{$original->id}/refrendar", ['monto_interes_pagado' => 50])
        ->assertCreated();

    expect($response->json('data.bienes.0.id'))->toBe($this->bien->id)
        ->and($response->json('data.refrendo_de_credito_id'))->toBe($original->id)
        ->and($response->json('data.numero_refrendo'))->toBe(1)
        ->and($response->json('data.estado'))->toBe('activo');

    expect($original->fresh()->estado)->toBe('refrendado');

    $nuevoId = $response->json('data.id');
    $tipos = CreditoPrendario::find($nuevoId)->documentos()->pluck('tipo')->all();
    expect($tipos)->toBe(['adenda']);
});

it('rejects refrendo once max_refrendos is reached', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1, 'max_refrendos' => 1,
    ]);

    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'numero_refrendo' => 1]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$original->id}/refrendar", ['monto_interes_pagado' => 50])
        ->assertUnprocessable();
});

it('rejects refrendo on a crédito still pendiente', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $pendiente = CreditoPrendario::factory()->paraBien($this->bien)
        ->create(['registrado_por' => $this->asesor->id, 'estado' => 'pendiente']);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$pendiente->id}/refrendar", ['monto_interes_pagado' => 50])
        ->assertUnprocessable();
});

it('rejects refrendo with a zero or negative monto_interes_pagado', function () {
    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$activo->id}/refrendar", ['monto_interes_pagado' => 0])
        ->assertUnprocessable();
});
