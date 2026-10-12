<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\Credito\Services\LiquidacionSaldoDeudorService;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create(['razon_social' => 'CREDIMAS ORIENTE E.I.R.L.', 'ruc' => '20602137903']);
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->configuracion = ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'hipotecario',
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 30, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
        'tea_compensatoria_maxima' => 20, 'tasa_moratoria_anual' => 10,
    ]);

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create(['nombre' => 'Erick Alfonso', 'apellido' => 'Echevarria Ramirez']);
    $this->inmueble = Inmueble::factory()->paraCliente($cliente)->create();

    // Crédito de 12,000 desembolsado el 01/01/2026 con dos cuotas de capital
    // de 6,000 (01/02 y 01/03).
    $this->credito = Credito::factory()->paraInmueble($this->inmueble)->create([
        'monto_prestamo' => 12000, 'tipo_interes' => 'compuesto', 'tipo_cuota' => 'mensual', 'numero_cuotas' => 2,
        'estado' => 'vencido', 'fecha_desembolso' => '2026-01-01', 'fecha_vencimiento' => '2026-02-01',
    ]);
    CuotaCredito::factory()->paraCredito($this->credito)->createMany([
        ['numero_cuota' => 1, 'fecha_vencimiento' => '2026-02-01', 'monto_capital' => 6000, 'monto_interes' => 500, 'monto_total' => 6500],
        ['numero_cuota' => 2, 'fecha_vencimiento' => '2026-03-01', 'monto_capital' => 6000, 'monto_interes' => 500, 'monto_total' => 6500],
    ]);
});

function tasaDiaria(float $anualPorcentaje): float
{
    return (1 + $anualPorcentaje / 100) ** (1 / 360) - 1;
}

function registrarPago(Credito $credito, float $monto, string $fecha, string $estado = 'registrado'): Cobro
{
    return Cobro::factory()->create([
        'empresa_id' => $credito->empresa_id, 'cliente_id' => $credito->cliente_id, 'credito_id' => $credito->id,
        'operacion' => 'pago_cuota', 'estado' => $estado, 'monto_pagado' => $monto, 'vuelto' => 0,
        'created_at' => $fecha.' 10:00:00',
    ]);
}

it('liquidates with the legal rates, moratorio only on overdue capital, and imputes payments to interest first', function () {
    registrarPago($this->credito, 1000, '2026-02-15');

    $l = app(LiquidacionSaldoDeudorService::class)->liquidar($this->credito, CarbonImmutable::parse('2026-04-01'));

    $ted = tasaDiaria(20);
    $tmd = tasaDiaria(10);

    // Tramo 1 (01/01 → 15/02, 45 días): compensatorio sobre 12,000; moratorio
    // sobre la cuota 1 (6,000) desde el 01/02, 14 días.
    $comp1 = round(12000 * $ted * 45, 2);
    $mor1 = round(6000 * $tmd * 14, 2);
    $aCapital = round(1000 - $comp1 - $mor1, 2);
    $capitalVivo = 12000 - $aCapital;

    // Tramo 2 (15/02 → 01/04, 45 días): compensatorio sobre el capital vivo;
    // moratorio sobre lo vencido menos lo amortizado — 14 días con solo la
    // cuota 1 vencida y 31 días con ambas.
    $comp2 = round($capitalVivo * $ted * 45, 2);
    $mor2 = round((6000 - $aCapital) * $tmd * 14 + (12000 - $aCapital) * $tmd * 31, 2);

    expect($l['fecha_origen'])->toBe('2026-01-01')
        ->and($l['dias'])->toBe(90)
        ->and($l['tramos'])->toHaveCount(2)
        ->and((float) $l['tramos'][0]['compensatorio'])->toBe($comp1)
        ->and((float) $l['tramos'][0]['moratorio'])->toBe($mor1)
        ->and((float) $l['tramos'][0]['aplicado_intereses'])->toBe(round($comp1 + $mor1, 2))
        ->and((float) $l['tramos'][0]['aplicado_capital'])->toBe($aCapital)
        ->and((float) $l['tramos'][1]['compensatorio'])->toBe($comp2)
        ->and((float) $l['tramos'][1]['moratorio'])->toBe($mor2)
        ->and($l['total_pagos'])->toBe('1000.00')
        ->and((float) $l['saldo'])->toBe(round(12000 + $comp1 + $comp2 + $mor1 + $mor2 - 1000, 2));
});

it('absorbs a payment smaller than the accrued interest entirely, leaving the capital intact', function () {
    registrarPago($this->credito, 100, '2026-02-15');

    $l = app(LiquidacionSaldoDeudorService::class)->liquidar($this->credito, CarbonImmutable::parse('2026-03-01'));

    expect($l['tramos'][0]['aplicado_intereses'])->toBe('100.00')
        ->and($l['tramos'][0]['aplicado_capital'])->toBe('0.00')
        ->and($l['tramos'][0]['capital_vivo'])->toBe('12000.00');
});

it('takes the origin and capital from the original crédito and the payments of the whole chain', function () {
    registrarPago($this->credito, 100, '2026-02-15');

    $sucesor = Credito::factory()->paraInmueble($this->inmueble)->create([
        'monto_prestamo' => 6000, 'tipo_interes' => 'compuesto', 'tipo_cuota' => 'mensual', 'numero_cuotas' => 1,
        'estado' => 'activo', 'fecha_desembolso' => '2026-02-01', 'pago_cuota_de_credito_id' => $this->credito->id,
    ]);
    registrarPago($sucesor, 200, '2026-03-10');
    registrarPago($sucesor, 999, '2026-03-11', 'anulado');

    $desdeSucesor = app(LiquidacionSaldoDeudorService::class)->liquidar($sucesor, CarbonImmutable::parse('2026-04-01'));
    $desdeOriginal = app(LiquidacionSaldoDeudorService::class)->liquidar($this->credito, CarbonImmutable::parse('2026-04-01'));

    expect($desdeSucesor['fecha_origen'])->toBe('2026-01-01')
        ->and($desdeSucesor['capital_inicial'])->toBe('12000.00')
        ->and($desdeSucesor['pagos'])->toBe(['100.00', '200.00'])
        ->and($desdeSucesor['total_pagos'])->toBe('300.00')
        ->and($desdeSucesor['saldo'])->toBe($desdeOriginal['saldo']);
});

it('keeps the summary identity: capital + interests − payments = saldo', function () {
    registrarPago($this->credito, 1000, '2026-01-07');
    registrarPago($this->credito, 200, '2026-02-12');
    registrarPago($this->credito, 300, '2026-02-14');

    $l = app(LiquidacionSaldoDeudorService::class)->liquidar($this->credito, CarbonImmutable::parse('2026-09-10'));

    $suma = bcsub(bcadd(bcadd($l['capital_inicial'], $l['interes_compensatorio'], 2), $l['interes_moratorio'], 2), $l['total_pagos'], 2);

    expect($l['saldo'])->toBe($suma)
        ->and($l['tramos'])->toHaveCount(4)
        ->and(collect($l['tramos'])->sum('dias'))->toBe($l['dias']);
});

it('streams the saldo_deudor PDF and renders the liquidation', function () {
    registrarPago($this->credito, 1000, '2026-02-15');
    $documento = DocumentoCredito::query()->create([
        'credito_id' => $this->credito->id, 'empresa_id' => $this->empresa->id, 'tipo' => 'saldo_deudor',
        'generado_por' => $this->admin->id, 'generado_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $this->get("/api/creditos-prendarios/{$this->credito->id}/documentos/{$documento->id}/ver")
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->empresa->update(['firma_path' => 'empresas/firma.png']);
    $l = app(LiquidacionSaldoDeudorService::class)->liquidar($this->credito, CarbonImmutable::parse('2026-04-01'));
    $html = view('modules.credito-hipotecario.documentos.saldo_deudor', [
        'credito' => $this->credito->load(['cliente', 'empresa']), 'documento' => $documento, 'liquidacion' => $l,
        'fotoDataUri' => fn (?string $path): string => "data:image/png;base64,{$path}",
    ])->render();

    // La firma de la empresa va justo antes de la razón social.
    expect($html)->toContain('data:image/png;base64,empresas/firma.png')
        ->and(strpos($html, 'data:image/png;base64,empresas/firma.png'))->toBeLessThan(strrpos($html, 'CREDIMAS ORIENTE E.I.R.L.'))
        ->and($html)->toContain('ECHEVARRIA RAMIREZ ERICK ALFONSO')
        ->and($html)->toContain('CREDIMAS ORIENTE E.I.R.L.')
        ->and($html)->toContain('20602137903')
        ->and($html)->toContain('S/ 12,000.00')
        ->and($html)->toContain('TEA 20.00%')
        ->and($html)->toContain('S/ '.number_format((float) $l['saldo'], 2));
});

it('streams a projection for a crédito not yet disbursed instead of failing', function () {
    $this->credito->update(['estado' => 'pendiente', 'fecha_desembolso' => null, 'fecha_vencimiento' => null]);
    $this->credito->cuotas()->delete();
    $documento = DocumentoCredito::query()->create([
        'credito_id' => $this->credito->id, 'empresa_id' => $this->empresa->id, 'tipo' => 'saldo_deudor',
        'generado_por' => $this->admin->id, 'generado_at' => now(),
    ]);

    $l = app(LiquidacionSaldoDeudorService::class)->liquidar($this->credito, CarbonImmutable::parse('2026-04-01'));

    expect($l['proyectado'])->toBeTrue()
        ->and($l['dias'])->toBe(0)
        ->and($l['interes_compensatorio'])->toBe('0.00')
        ->and($l['saldo'])->toBe('12000.00');

    Sanctum::actingAs($this->admin, ['*']);

    $this->get("/api/creditos-prendarios/{$this->credito->id}/documentos/{$documento->id}/ver")
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('refuses the saldo_deudor documento until the legal rates are configured', function () {
    $this->configuracion->update(['tea_compensatoria_maxima' => null]);
    $documento = DocumentoCredito::query()->create([
        'credito_id' => $this->credito->id, 'empresa_id' => $this->empresa->id, 'tipo' => 'saldo_deudor',
        'generado_por' => $this->admin->id, 'generado_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson("/api/creditos-prendarios/{$this->credito->id}/documentos/{$documento->id}/ver")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Registra la TEA compensatoria máxima y la tasa moratoria anual en la configuración de créditos hipotecarios para emitir la liquidación de saldo deudor.');
});

it('saves the legal rates in the hipotecario configuration and keeps them when omitted', function () {
    $general = User::factory()->forAgencia($this->agencia)->create();
    $general->assignRole('administrador_general');
    Sanctum::actingAs($general, ['*']);

    $base = ['tipo_credito' => 'hipotecario', 'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 30, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1];

    $this->putJson('/api/configuraciones-credito-prendario', [...$base, 'tea_compensatoria_maxima' => 115.14, 'tasa_moratoria_anual' => 3.5])
        ->assertOk()
        ->assertJsonPath('data.tea_compensatoria_maxima', '115.14')
        ->assertJsonPath('data.tasa_moratoria_anual', '3.50');

    $this->putJson('/api/configuraciones-credito-prendario', $base)->assertOk();

    expect($this->configuracion->fresh()->tea_compensatoria_maxima)->toBe('115.14');
});
