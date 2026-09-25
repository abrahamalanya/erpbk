<?php

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->boveda = Boveda::factory()->principalDe($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');

    $this->credito = Credito::factory()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'registrado_por' => $this->asesor->id,
    ]);
});

/**
 * Caja::user_id es único — firstOrCreate reusa la caja/ciclo del usuario
 * entre llamadas del mismo test.
 */
function cajaCicloDe(Empresa $empresa, Agencia $agencia, User $user): CajaCiclo
{
    $caja = Caja::query()->firstOrCreate(
        ['user_id' => $user->id],
        ['empresa_id' => $empresa->id, 'agencia_id' => $agencia->id],
    );

    return CajaCiclo::query()->firstOrCreate(
        ['caja_id' => $caja->id],
        ['empresa_id' => $empresa->id, 'fecha' => now()->toDateString(), 'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now()],
    );
}

/**
 * @param  array<string, mixed>  $extra
 */
function movimiento(CajaCiclo $ciclo, Empresa $empresa, string $tipo, float $monto, string $fecha, User $registradoPor, array $extra = []): CajaMovimiento
{
    return CajaMovimiento::query()->create(array_merge([
        'caja_ciclo_id' => $ciclo->id,
        'empresa_id' => $empresa->id,
        'tipo' => $tipo,
        'monto' => $monto,
        'registrado_por' => $registradoPor->id,
        'fecha_caja' => $fecha,
    ], $extra));
}

/**
 * Un billetaje aprobado: el CajaMovimiento que genera trae registrado_por =
 * el aprobador, no el solicitante (ver Billetaje::solicitado_por) — así se
 * puede probar que el reporte agrupa por el solicitante real.
 */
function billetajeAprobado(CajaCiclo $ciclo, Empresa $empresa, Boveda $boveda, User $solicitante, User $aprobador, float $monto, string $fecha): CajaMovimiento
{
    $billetaje = Billetaje::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'boveda_id' => $boveda->id,
        'empresa_id' => $empresa->id,
        'monto' => $monto,
        'estado' => 'aprobado',
        'solicitado_por' => $solicitante->id,
        'aprobado_por' => $aprobador->id,
        'fecha_resolucion' => $fecha,
    ]);

    return movimiento($ciclo, $empresa, 'billetaje', $monto, $fecha, $aprobador, ['billetaje_id' => $billetaje->id]);
}

function cobroEnCaja(CajaCiclo $ciclo, Empresa $empresa, Credito $credito, Cliente $cliente, User $asesor, float $monto, string $fecha): Cobro
{
    $cobro = Cobro::query()->create([
        'empresa_id' => $empresa->id,
        'cliente_id' => $cliente->id,
        'credito_id' => $credito->id,
        'caja_ciclo_id' => $ciclo->id,
        'registrado_por' => $asesor->id,
        'operacion' => 'pago_cuota',
        'estado' => 'registrado',
        'monto_pagado' => $monto,
        'medio' => 'efectivo',
        'interes' => 0,
        'vuelto' => 0,
    ]);

    // created_at no está en $fillable — create() lo ignora silenciosamente
    // y Eloquent lo pisa con now(); hay que forzarlo después.
    $cobro->forceFill(['created_at' => $fecha])->save();

    return $cobro;
}

it('sums the 5 categories for a date range, without double-counting cobranza as ingresos, and computes saldo_caja', function () {
    $ciclo = cajaCicloDe($this->empresa, $this->agencia, $this->asesor);
    $concepto = Concepto::factory()->create(['empresa_id' => $this->empresa->id]);

    movimiento($ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, ['concepto_id' => $concepto->id]);
    movimiento($ciclo, $this->empresa, 'egreso', 40, '2026-09-05', $this->asesor, ['concepto_id' => $concepto->id]);
    movimiento($ciclo, $this->empresa, 'egreso', 500, '2026-09-05', $this->asesor, ['credito_id' => $this->credito->id]);
    billetajeAprobado($ciclo, $this->empresa, $this->boveda, $this->asesor, $this->administradorAgencia, 300, '2026-09-05');
    cobroEnCaja($ciclo, $this->empresa, $this->credito, $this->cliente, $this->asesor, 150, '2026-09-05 10:00:00');
    // El "ingreso fantasma" que todo Cobro real genera en caja (ver CreditoService::registrarCobroEnCaja) — sin concepto_id, así que NO debe sumar a "ingresos".
    movimiento($ciclo, $this->empresa, 'ingreso', 150, '2026-09-05', $this->asesor);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/flujo-caja?desde=2026-09-05&hasta=2026-09-05')->assertSuccessful();

    $totales = $response->json('data.totales');
    expect($totales['billetaje'])->toEqual(300.0)
        ->and($totales['ingresos'])->toEqual(100.0)
        ->and($totales['egresos'])->toEqual(40.0)
        ->and($totales['cobranza'])->toEqual(150.0)
        ->and($totales['desembolsos'])->toEqual(500.0)
        // saldo_apertura(0) + (ingresos 100 + ingreso-de-cobro 150 + billetaje 300) - (egresos 40 + desembolso 500) = 10
        ->and($response->json('data.saldo_caja'))->toEqual(10.0);

    $porAsesorBilletaje = collect($response->json('data.porAsesor.billetaje'));
    expect($porAsesorBilletaje)->toHaveCount(1)
        ->and($porAsesorBilletaje->first()['asesor_id'])->toBe($this->asesor->id)
        ->and($porAsesorBilletaje->first()['monto'])->toEqual(300.0);
});

it('filters by agencia and by asesor', function () {
    $ciclo = cajaCicloDe($this->empresa, $this->agencia, $this->asesor);
    movimiento($ciclo, $this->empresa, 'egreso', 40, '2026-09-05', $this->asesor, ['credito_id' => $this->credito->id]);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroCliente = Cliente::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor = User::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCredito = Credito::factory()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $otraAgencia->id,
        'cliente_id' => $otroCliente->id,
        'registrado_por' => $otroAsesor->id,
    ]);
    $otroCiclo = cajaCicloDe($this->empresa, $otraAgencia, $otroAsesor);
    movimiento($otroCiclo, $this->empresa, 'egreso', 999, '2026-09-05', $otroAsesor, ['credito_id' => $otroCredito->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $porAgencia = $this->getJson("/api/reportes/flujo-caja?desde=2026-09-05&hasta=2026-09-05&agencia_id={$this->agencia->id}")->assertSuccessful();
    expect($porAgencia->json('data.totales.desembolsos'))->toEqual(40.0);

    $porAsesor = $this->getJson("/api/reportes/flujo-caja?desde=2026-09-05&hasta=2026-09-05&asesor_id={$otroAsesor->id}")->assertSuccessful();
    expect($porAsesor->json('data.totales.desembolsos'))->toEqual(999.0);
});

it('scopes visibility by role: asesor sees only their own caja, administrador_agencia only their agencia, administrador_general sees all', function () {
    $ciclo = cajaCicloDe($this->empresa, $this->agencia, $this->asesor);
    $concepto = Concepto::factory()->create(['empresa_id' => $this->empresa->id]);
    movimiento($ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, ['concepto_id' => $concepto->id]);

    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCiclo = cajaCicloDe($this->empresa, $this->agencia, $otroAsesor);
    movimiento($otroCiclo, $this->empresa, 'ingreso', 50, '2026-09-05', $otroAsesor, ['concepto_id' => $concepto->id]);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $asesorOtraAgencia = User::factory()->forAgencia($otraAgencia)->create();
    $asesorOtraAgencia->assignRole('asesor');
    $cicloOtraAgencia = cajaCicloDe($this->empresa, $otraAgencia, $asesorOtraAgencia);
    movimiento($cicloOtraAgencia, $this->empresa, 'ingreso', 25, '2026-09-05', $asesorOtraAgencia, ['concepto_id' => $concepto->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $comoAsesor = $this->getJson('/api/reportes/flujo-caja?desde=2026-09-05&hasta=2026-09-05')->assertSuccessful();
    expect($comoAsesor->json('data.totales.ingresos'))->toEqual(100.0);

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $comoAdminAgencia = $this->getJson('/api/reportes/flujo-caja?desde=2026-09-05&hasta=2026-09-05')->assertSuccessful();
    expect($comoAdminAgencia->json('data.totales.ingresos'))->toEqual(150.0);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $comoAdminGeneral = $this->getJson('/api/reportes/flujo-caja?desde=2026-09-05&hasta=2026-09-05')->assertSuccessful();
    expect($comoAdminGeneral->json('data.totales.ingresos'))->toEqual(175.0);
});

it('fills all 12 months with 0 for the annual line chart summary', function () {
    $ciclo = cajaCicloDe($this->empresa, $this->agencia, $this->asesor);
    $concepto = Concepto::factory()->create(['empresa_id' => $this->empresa->id]);

    movimiento($ciclo, $this->empresa, 'ingreso', 100, '2026-01-10', $this->asesor, ['concepto_id' => $concepto->id]);
    movimiento($ciclo, $this->empresa, 'ingreso', 50, '2026-06-15', $this->asesor, ['concepto_id' => $concepto->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/flujo-caja/anual?anio=2026')->assertSuccessful();

    $porMes = collect($response->json('data.porMes'));
    expect($porMes)->toHaveCount(12)
        ->and($porMes->firstWhere('mes', 1)['ingresos'])->toEqual(100.0)
        ->and($porMes->firstWhere('mes', 6)['ingresos'])->toEqual(50.0)
        ->and($porMes->firstWhere('mes', 3)['ingresos'])->toEqual(0.0)
        ->and($porMes->firstWhere('mes', 3)['billetaje'])->toEqual(0.0);
});

it('fills all days of the month with 0 for the monthly line chart summary', function () {
    $ciclo = cajaCicloDe($this->empresa, $this->agencia, $this->asesor);
    $concepto = Concepto::factory()->create(['empresa_id' => $this->empresa->id]);

    movimiento($ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, ['concepto_id' => $concepto->id]);
    movimiento($ciclo, $this->empresa, 'ingreso', 50, '2026-09-20', $this->asesor, ['concepto_id' => $concepto->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/flujo-caja/mensual?mes=2026-09')->assertSuccessful();

    $porDia = collect($response->json('data.porDia'));
    expect($porDia)->toHaveCount(30)
        ->and($porDia->firstWhere('dia', 5)['ingresos'])->toEqual(100.0)
        ->and($porDia->firstWhere('dia', 20)['ingresos'])->toEqual(50.0)
        ->and($porDia->firstWhere('dia', 1)['ingresos'])->toEqual(0.0)
        ->and($porDia->firstWhere('dia', 1)['billetaje'])->toEqual(0.0);
});
