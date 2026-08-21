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

    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');
    $response = $this->postJson("/api/creditos-prendarios/{$original->id}/refrendar", ['monto_pagado' => $sugerido])
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

    $this->postJson("/api/creditos-prendarios/{$original->id}/refrendar", ['monto_pagado' => 50])
        ->assertUnprocessable();
});

it('rejects refrendo on a crédito still pendiente', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $pendiente = CreditoPrendario::factory()->paraBien($this->bien)
        ->create(['registrado_por' => $this->asesor->id, 'estado' => 'pendiente']);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$pendiente->id}/refrendar", ['monto_pagado' => 50])
        ->assertUnprocessable();
});

it('rejects refrendo with a monto_interes_pagado below the calculated prorated interest', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_refrendos' => null,
    ]);

    // Día 0 desde el desembolso -> se cobra el mínimo configurado (15 días):
    // 1000 x 20% x 15 / 3000 = 100.00.
    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$activo->id}/refrendar", ['monto_pagado' => 50])
        ->assertUnprocessable();

    $this->postJson("/api/creditos-prendarios/{$activo->id}/refrendar", ['monto_pagado' => 100])
        ->assertCreated();
});

it('exposes monto_refrendo_sugerido on show() when activo', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->asesor, ['*']);

    $show = $this->getJson("/api/creditos-prendarios/{$activo->id}")->assertSuccessful();
    expect($show->json('data.monto_refrendo_sugerido.total'))->toBe('100.00')
        ->and($show->json('data.monto_refrendo_sugerido.interes'))->toBe('100.00');
});

it('rejects refrendo with a zero or negative monto_pagado', function () {
    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$activo->id}/refrendar", ['monto_pagado' => 0])
        ->assertUnprocessable();
});

it('rejects refrendo when monto_pagado covers the full total, suggesting Liquidar instead', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_refrendos' => null,
    ]);

    // interés = 1000 x 20% x 15 / 3000 = 100.00; total = 1100.00.
    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$activo->id}/refrendar", ['monto_pagado' => 1100])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El monto pagado (1100) cubre el total del crédito (1100.00); selecciona Liquidar para cancelarlo.');
});

it('abona a capital cuando el monto pagado supera el interés, generando un sucesor con capital reducido y su propio cronograma', function () {
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_refrendos' => null,
    ]);

    // interés = 1000 x 20% x 15 / 3000 = 100.00; paga 300 -> abono 200 a capital -> sucesor con 800.
    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20, 'tipo_cuota' => 'mensual']);

    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson("/api/creditos-prendarios/{$activo->id}/refrendar", ['monto_pagado' => 300])
        ->assertCreated();

    expect($response->json('data.monto_prestamo'))->toBe('800.00');

    $nuevoId = $response->json('data.id');
    $cuotas = CreditoPrendario::find($nuevoId)->cuotas;
    expect($cuotas)->toHaveCount(1)
        ->and((string) $cuotas->first()->monto_capital)->toBe('800.00');
});
