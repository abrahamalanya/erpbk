<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoService;
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
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro']);
});

it('incluye la mora en el monto de liquidación sugerido de un crédito vencido', function () {
    $credito = Credito::factory()->paraBien($this->bien)->vencido(10)->create([
        'monto_prestamo' => 1000, 'interes' => 15,
        'registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$credito->id}")
        ->assertSuccessful()
        ->json('data.monto_liquidacion_sugerido');

    // monto_prestamo=1000, tasa_mora_diaria=0.05%, 10 días vencido: 1000 * 0.0005 * 10 = 5.00
    expect($sugerido['mora'])->toBe('5.00')
        ->and($sugerido['dias_mora'])->toBe(10)
        ->and($sugerido['total'])->toBe(bcadd(bcadd('1000', $sugerido['interes'], 2), '5.00', 2));
});

it('no cobra mora sobre un crédito activo (todavía dentro del plazo)', function () {
    $credito = Credito::factory()->paraBien($this->bien)->activo()->create([
        'monto_prestamo' => 1000, 'interes' => 15,
        'registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$credito->id}")
        ->assertSuccessful()
        ->json('data.monto_liquidacion_sugerido');

    expect($sugerido['mora'])->toBe('0.00')
        ->and($sugerido['dias_mora'])->toBe(0);
});

it('rechaza liquidar un crédito vencido si el monto pagado no cubre la mora', function () {
    $credito = Credito::factory()->paraBien($this->bien)->vencido(10)->create([
        'monto_prestamo' => 1000, 'interes' => 15,
        'registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $totalSinMora = bcadd('1000', app(CreditoService::class)->calcularMontoLiquidacion($credito)['interes'], 2);

    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", [
        'monto_pagado' => $totalSinMora, 'medio' => 'efectivo',
    ])->assertUnprocessable();
});

it('liquida un crédito vencido cuando el monto pagado sí cubre capital + interés + mora', function () {
    $credito = Credito::factory()->paraBien($this->bien)->vencido(10)->create([
        'monto_prestamo' => 1000, 'interes' => 15,
        'registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")
        ->json('data.monto_liquidacion_sugerido.total');

    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", [
        'monto_pagado' => $total, 'medio' => 'efectivo',
    ])->assertSuccessful();

    expect($credito->fresh()->estado)->toBe('liquidado_pendiente');
});
