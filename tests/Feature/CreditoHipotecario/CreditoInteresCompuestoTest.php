<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'hipotecario',
        'interes_default' => 8, 'plazo_dias' => 30, 'dias_espera_mora' => 30,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_cuotas' => 24,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 200000, 'abierta_at' => now(),
    ]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
    $cajaAdmin = Caja::factory()->create(['user_id' => $this->adminAgencia->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $cajaAdmin->id, 'empresa_id' => $cajaAdmin->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 200000, 'abierta_at' => now(),
    ]);

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->inmueble = Inmueble::factory()->paraCliente($this->cliente)->create(['valorizacion' => 150000]);
});

function registrarYDesembolsarCompuesto($test, int $numeroCuotas = 12, string $monto = '1000', ?string $fechaDesembolso = null): int
{
    Sanctum::actingAs($test->asesor, ['*']);

    $creditoId = $test->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$test->inmueble->id],
        'supervisado_por' => $test->adminAgencia->id,
        'monto_prestamo' => $monto,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => $numeroCuotas,
        'tipo_interes' => 'compuesto',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($test->adminAgencia, ['*']);
    $test->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();
    // fecha_desembolso explícita exige permiso de editar (admin), no asesor.
    $test->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar", array_filter(['fecha_desembolso' => $fechaDesembolso]))->assertSuccessful();

    return $creditoId;
}

it('generates a French-system cronograma with real calendar-month dates (1000 @ 8%, 12 cuotas)', function () {
    $fechaDesembolso = now()->toDateString();
    $creditoId = registrarYDesembolsarCompuesto($this, fechaDesembolso: $fechaDesembolso);

    $cuotas = Credito::find($creditoId)->cuotas()->orderBy('numero_cuota')->get();
    $base = Carbon::parse($fechaDesembolso);

    expect($cuotas)->toHaveCount(12)
        ->and($cuotas[0]->fecha_vencimiento->toDateString())->toBe($base->copy()->addMonthsNoOverflow(1)->toDateString())
        ->and((string) $cuotas[0]->monto_total)->toBe('132.70')
        ->and((string) $cuotas[0]->monto_interes)->toBe('80.00')
        ->and((string) $cuotas[0]->monto_capital)->toBe('52.70')
        ->and($cuotas[1]->fecha_vencimiento->toDateString())->toBe($base->copy()->addMonthsNoOverflow(2)->toDateString())
        ->and((string) $cuotas[1]->monto_interes)->toBe('75.80')
        ->and((string) $cuotas[1]->monto_capital)->toBe('56.90')
        // Cuota 5 cae en un mes de día distinto (calendario real), pero el
        // interés sigue la tasa nominal fija (no varía con el mes).
        ->and($cuotas[4]->fecha_vencimiento->toDateString())->toBe($base->copy()->addMonthsNoOverflow(5)->toDateString())
        ->and((string) $cuotas[4]->monto_interes)->toBe('61.00')
        // Última cuota: absorbe el saldo insoluto exacto, cerrando en 0.
        ->and($cuotas[11]->fecha_vencimiento->toDateString())->toBe($base->copy()->addMonthsNoOverflow(12)->toDateString())
        ->and((string) $cuotas[11]->monto_total)->toBe('132.70');
});

it('pays a cuota, reduces the saldo insoluto and chains a successor crédito', function () {
    $fechaDesembolso = now()->toDateString();
    $creditoId = registrarYDesembolsarCompuesto($this, fechaDesembolso: $fechaDesembolso);
    $base = Carbon::parse($fechaDesembolso);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuota", [
        'monto_pagado' => '132.70',
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.numero_cuotas'))->toBe(11)
        ->and((string) $response->json('data.monto_prestamo'))->toBe('947.30')
        ->and($response->json('data.tipo_interes'))->toBe('compuesto')
        ->and($response->json('data.estado'))->toBe('activo')
        ->and(Carbon::parse($response->json('data.fecha_desembolso'))->toDateString())->toBe($base->copy()->addMonthsNoOverflow(1)->toDateString())
        ->and(Carbon::parse($response->json('data.fecha_vencimiento'))->toDateString())->toBe($base->copy()->addMonthsNoOverflow(2)->toDateString())
        ->and($response->json('data.pago_cuota_de_credito_id'))->toBe($creditoId);

    expect(Credito::find($creditoId)->estado)->toBe('cuota_pagada');

    $siguienteCuota = Credito::find($response->json('data.id'))->cuotas()->orderBy('numero_cuota')->first();
    expect((string) $siguienteCuota->monto_interes)->toBe('75.80')
        ->and($siguienteCuota->fecha_vencimiento->toDateString())->toBe($base->copy()->addMonthsNoOverflow(2)->toDateString());
});

it('denies refrendar/adendar on a compuesto crédito and points to pagar-cuota', function () {
    $creditoId = registrarYDesembolsarCompuesto($this, numeroCuotas: 2, monto: '500');

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/refrendar", ['monto_pagado' => 10, 'medio' => 'efectivo'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Este crédito es de interés compuesto: usa "pagar cuota" en vez de refrendar.');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/adendar", ['monto_pagado' => 10, 'medio' => 'efectivo'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Este crédito es de interés compuesto: usa "pagar cuota" en vez de adendar.');
});

it('requires numero_cuotas when tipo_interes is compuesto', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 1000,
        'interes' => 8,
        'tipo_cuota' => 'mensual',
        'tipo_interes' => 'compuesto',
    ])->assertStatus(422)->assertJsonValidationErrors('numero_cuotas');
});

it('calculates mora on the overdue cuota amount, not the total saldo insoluto', function () {
    $credito = Credito::factory()->hipotecario()->paraInmueble($this->inmueble)->vencido(diasVencido: 5)->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'registrado_por' => $this->asesor->id,
        'monto_prestamo' => 947.30,
        'interes' => 8,
        'tipo_interes' => 'compuesto',
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 11,
    ]);

    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 1,
        'fecha_vencimiento' => now()->subDays(5)->toDateString(),
        'monto_capital' => 56.92,
        'monto_interes' => 75.78,
        'monto_total' => 132.70,
    ]);

    $mora = app(CreditoService::class)->calcularMora($credito->fresh());

    // 132.70 × 1% × 5 días = 6.635 -> 6.63 (bcmath trunca, no redondea)
    expect($mora)->toBe('6.63');
});

it('completes the last cuota by moving straight to liquidado_pendiente (no successor)', function () {
    $credito = Credito::factory()->hipotecario()->paraInmueble($this->inmueble)->activo()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'registrado_por' => $this->asesor->id,
        'monto_prestamo' => 130.00,
        'interes' => 8,
        'tipo_interes' => 'compuesto',
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 1,
    ]);

    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 1,
        'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        'monto_capital' => 130.00,
        'monto_interes' => 2.70,
        'monto_total' => 132.70,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$credito->id}/pagar-cuota", [
        'monto_pagado' => '132.70',
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.id'))->toBe($credito->id)
        ->and($response->json('data.estado'))->toBe('liquidado_pendiente');

    expect($credito->fresh()->documentos()->where('tipo', 'devolucion')->exists())->toBeTrue();
});
