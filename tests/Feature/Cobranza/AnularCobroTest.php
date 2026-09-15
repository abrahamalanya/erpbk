<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
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

    $this->caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    $this->ciclo = CajaCiclo::query()->create([
        'caja_id' => $this->caja->id, 'empresa_id' => $this->caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
});

it('undoes a refrendo while the caja ciclo is still open: reverts estado, deletes the sucesor and the caja movimiento', function () {
    $original = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');
    $sucesorId = $this->postJson("/api/creditos-prendarios/{$original->id}/refrendar", ['monto_pagado' => $sugerido, 'medio' => 'efectivo'])
        ->assertCreated()->json('data.id');

    $cobro = Cobro::query()->where('credito_id', $original->id)->firstOrFail();
    expect($cobro->caja_movimiento_id)->not->toBeNull();
    expect(CajaMovimiento::find($cobro->caja_movimiento_id))->not->toBeNull();

    $this->postJson("/api/cobros/{$cobro->id}/anular", ['motivo' => 'Me equivoqué de monto'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'activo');

    expect($original->fresh()->estado)->toBe('activo')
        ->and(Credito::find($sucesorId))->toBeNull()
        ->and(CajaMovimiento::find($cobro->caja_movimiento_id))->toBeNull();

    $cobro = $cobro->fresh();
    expect($cobro->estado)->toBe('anulado')
        ->and($cobro->anulado_por)->toBe($this->asesor->id)
        ->and($cobro->motivo_anulacion)->toBe('Me equivoqué de monto')
        ->and($cobro->anulado_at)->not->toBeNull();
});

it('undoes a liquidación while the caja ciclo is still open: reverts estado and removes devolucion/voucher_pago', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->firstOrFail();
    expect($credito->fresh()->documentos()->whereIn('tipo', ['devolucion', 'voucher_pago'])->count())->toBe(2);

    $this->postJson("/api/cobros/{$cobro->id}/anular", [])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'activo');

    expect($credito->fresh()->estado)->toBe('activo')
        ->and($credito->fresh()->documentos()->whereIn('tipo', ['devolucion', 'voucher_pago'])->count())->toBe(0);
});

it('restores vencido (not activo) when that was the estado before the cobro', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->vencido(diasVencido: 5)
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->firstOrFail();
    expect($cobro->credito_estado_anterior)->toBe('vencido');

    $this->postJson("/api/cobros/{$cobro->id}/anular", [])->assertSuccessful();

    expect($credito->fresh()->estado)->toBe('vencido');
});

it('rejects anulando a cobro once the caja ciclo where it was registered is closed', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->firstOrFail();

    $this->ciclo->update(['estado' => 'cerrada']);

    $this->postJson("/api/cobros/{$cobro->id}/anular", [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Solo puedes anular un cobro mientras el ciclo de caja donde se registró sigue abierto.');

    expect($credito->fresh()->estado)->toBe('liquidado_pendiente');
});

it('rejects anulando a cobro that is already anulado', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->firstOrFail();
    $this->postJson("/api/cobros/{$cobro->id}/anular", [])->assertSuccessful();

    $this->postJson("/api/cobros/{$cobro->id}/anular", [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Este cobro ya está anulado.');
});

it('rejects anulando a refrendo once the sucesor already has its own cobro', function () {
    $original = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');
    $sucesorId = $this->postJson("/api/creditos-prendarios/{$original->id}/refrendar", ['monto_pagado' => $sugerido, 'medio' => 'efectivo'])
        ->assertCreated()->json('data.id');

    $totalSucesor = $this->getJson("/api/creditos-prendarios/{$sucesorId}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$sucesorId}/liquidar", ['monto_pagado' => $totalSucesor, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobroOriginal = Cobro::query()->where('credito_id', $original->id)->firstOrFail();

    $this->postJson("/api/cobros/{$cobroOriginal->id}/anular", [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se puede anular: ya se registró un cobro sobre el crédito sucesor.');
});

it('marks puede_anular true only for cobros in the actor\'s own currently open ciclo', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $response = $this->getJson('/api/cobros')->assertSuccessful();
    expect($response->json('data.data.0.puede_anular'))->toBeTrue();

    $this->ciclo->update(['estado' => 'cerrada']);
    $response = $this->getJson('/api/cobros')->assertSuccessful();
    expect($response->json('data.data.0.puede_anular'))->toBeFalse();
});

it('denies a user without cobranzas.registrar from anulando a cobro', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->firstOrFail();

    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();
    $sinPermiso->assignRole('secretaria');

    Sanctum::actingAs($sinPermiso, ['*']);
    $this->postJson("/api/cobros/{$cobro->id}/anular", [])->assertForbidden();
});
