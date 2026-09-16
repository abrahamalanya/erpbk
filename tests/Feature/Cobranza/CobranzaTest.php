<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
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

    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15,
        'tasa_mora_diaria' => 1, 'max_refrendos' => null,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro']);

    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
});

it('records a Cobro row when a crédito is refrendado', function () {
    $original = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);

    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$original->id}/refrendar", ['monto_pagado' => $sugerido, 'medio' => 'efectivo'])
        ->assertCreated();

    $cobro = Cobro::query()->where('credito_id', $original->id)->first();
    expect($cobro)->not->toBeNull()
        ->and($cobro->operacion)->toBe('refrendo')
        ->and($cobro->cliente_id)->toBe($this->cliente->id)
        ->and($cobro->registrado_por)->toBe($this->asesor->id)
        ->and($cobro->credito_sucesor_id)->not->toBeNull();
});

it('records a Cobro row when a crédito is liquidado', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);

    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->first();
    expect($cobro)->not->toBeNull()
        ->and($cobro->operacion)->toBe('liquidacion')
        ->and($cobro->credito_sucesor_id)->toBeNull();
});

it('lists cobros for the actor, scoped by crédito visibility', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $response = $this->getJson('/api/cobros')->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.credito_id'))->toBe($credito->id)
        ->and($response->json('data.data.0.operacion'))->toBe('liquidacion');
});

it('does not list cobros from another asesor\'s crédito', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    Sanctum::actingAs($otroAsesor, ['*']);
    $response = $this->getJson('/api/cobros')->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(0);
});

it('filters cobros by estado', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->firstOrFail();
    $this->postJson("/api/cobros/{$cobro->id}/anular", [])->assertSuccessful();

    $anulados = $this->getJson('/api/cobros?estado=anulado')->assertSuccessful();
    expect($anulados->json('data.data'))->toHaveCount(1)
        ->and($anulados->json('data.data.0.id'))->toBe($cobro->id);

    $registrados = $this->getJson('/api/cobros?estado=registrado')->assertSuccessful();
    expect($registrados->json('data.data'))->toHaveCount(0);
});

it('filters cobros by registrado_por', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    Sanctum::actingAs($adminAgencia, ['*']);
    $deEsteAsesor = $this->getJson("/api/cobros?registrado_por={$this->asesor->id}")->assertSuccessful();
    expect($deEsteAsesor->json('data.data'))->toHaveCount(1);

    $deOtroAsesor = $this->getJson("/api/cobros?registrado_por={$otroAsesor->id}")->assertSuccessful();
    expect($deOtroAsesor->json('data.data'))->toHaveCount(0);
});

it('filters anulaciones by anulado_desde/anulado_hasta, independent of desde/hasta', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->firstOrFail();
    $this->postJson("/api/cobros/{$cobro->id}/anular", [])->assertSuccessful();
    $cobro->update(['anulado_at' => now()->subDays(10)]);

    $fueraDeRango = $this->getJson('/api/cobros?estado=anulado&anulado_desde='.now()->subDays(2)->toDateString())->assertSuccessful();
    expect($fueraDeRango->json('data.data'))->toHaveCount(0);

    $dentroDeRango = $this->getJson('/api/cobros?estado=anulado&anulado_hasta='.now()->subDays(2)->toDateString())->assertSuccessful();
    expect($dentroDeRango->json('data.data'))->toHaveCount(1);
});

it('returns a client\'s activo/vencido créditos with suggested amounts', function () {
    $activo = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    $bien2 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro']);
    Credito::factory()->paraBien($bien2)
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id, 'estado' => 'pendiente']);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson("/api/cobros/creditos-pendientes/{$this->cliente->id}")->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($activo->id)
        ->and($response->json('data.0.monto_refrendo_sugerido.total'))->not->toBeNull()
        ->and($response->json('data.0.monto_liquidacion_sugerido.total'))->not->toBeNull();
});

it('denies a user without cobranzas.ver from listing cobros', function () {
    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();
    $sinPermiso->assignRole('secretaria'); // no tiene cobranzas.ver

    Sanctum::actingAs($sinPermiso, ['*']);
    $this->getJson('/api/cobros')->assertForbidden();
});
