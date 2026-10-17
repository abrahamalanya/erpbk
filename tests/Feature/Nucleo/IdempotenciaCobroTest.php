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

/**
 * Idempotencia sobre un endpoint real de cobro: es el caso que dispara la app
 * offline cuando reintenta un pago que quizá sí llegó al servidor (se perdió la
 * respuesta por falta de red). Ver App\Nucleo\Http\Middleware\EnsureIdempotency.
 */
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

    $caja = Caja::factory()->create([
        'user_id' => $this->asesor->id,
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
    ]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
});

it('reintentar una liquidación con la misma clave reenvía la respuesta sin crear otro cobro', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);

    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $cuerpo = ['monto_pagado' => $total, 'medio' => 'efectivo'];

    $primera = $this->withHeader('Idempotency-Key', 'cobro-uuid-1')
        ->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", $cuerpo);
    $segunda = $this->withHeader('Idempotency-Key', 'cobro-uuid-1')
        ->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", $cuerpo);

    $primera->assertSuccessful();
    $segunda->assertSuccessful();
    $segunda->assertHeader('Idempotency-Replayed', 'true');

    expect(Cobro::query()->where('credito_id', $credito->id)->count())->toBe(1);
    expect($segunda->json('data.cobro_id'))->toBe($primera->json('data.cobro_id'));
});

it('un cobro con clave distinta sí se ejecuta (no se confunde con el reintento)', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);

    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');

    // Primera liquidación con su clave.
    $this->withHeader('Idempotency-Key', 'cobro-a')
        ->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    // El crédito ya está liquidado: otra clave no debe reusar la respuesta guardada.
    $otraClave = $this->withHeader('Idempotency-Key', 'cobro-b')
        ->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo']);

    $otraClave->assertStatus(422);
    expect(Cobro::query()->where('credito_id', $credito->id)->count())->toBe(1);
});
