<?php

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

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->credito = Credito::factory()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'registrado_por' => $this->asesor->id,
    ]);

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
});

function cobroEnFecha(Empresa $empresa, Credito $credito, Cliente $cliente, User $asesor, string $fecha, float $monto, string $estado = 'registrado'): Cobro
{
    return Cobro::factory()->create([
        'empresa_id' => $empresa->id,
        'credito_id' => $credito->id,
        'cliente_id' => $cliente->id,
        'registrado_por' => $asesor->id,
        'monto_pagado' => $monto,
        'estado' => $estado,
        'created_at' => $fecha,
    ]);
}

/**
 * CajaMovimiento::caja_ciclo_id no es nullable — a diferencia de Cobro, no
 * basta con overridear atributos del factory: CajaMovimientoFactory crea
 * SIEMPRE (dentro de su propio definition(), antes de aplicar overrides) un
 * CajaCiclo→Caja→User nuevo con empresa_id null, y eso revienta el NOT NULL
 * antes de llegar a mis overrides. Se arma el ciclo real a mano y se crea el
 * movimiento con el modelo directo (sin factory) para evitar ese efecto
 * secundario — mismo patrón que usan los tests de Credito para "aperturar
 * caja" antes de desembolsar.
 *
 * cajas.user_id es único — firstOrCreate reusa la caja/ciclo del asesor
 * entre llamadas del mismo test en vez de violar esa restricción; el reporte
 * solo lee CajaMovimiento.fecha_caja, no el ciclo al que pertenece.
 */
function desembolsoEnFecha(Empresa $empresa, Agencia $agencia, Credito $credito, User $asesor, string $fecha, float $monto): CajaMovimiento
{
    $caja = Caja::query()->firstOrCreate(
        ['user_id' => $asesor->id],
        ['empresa_id' => $empresa->id, 'agencia_id' => $agencia->id],
    );
    $ciclo = CajaCiclo::query()->firstOrCreate(
        ['caja_id' => $caja->id],
        ['empresa_id' => $empresa->id, 'fecha' => $fecha, 'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => $fecha],
    );

    return CajaMovimiento::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'empresa_id' => $empresa->id,
        'tipo' => 'egreso',
        'concepto_id' => null,
        'credito_id' => $credito->id,
        'registrado_por' => $asesor->id,
        'monto' => $monto,
        'fecha_caja' => $fecha,
    ]);
}

it('sums cobranza per day and fills missing days with 0', function () {
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-05 10:00:00', 100);
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-05 15:00:00', 50);
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-12 09:00:00', 200);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/cobranza-mensual?mes=2026-09')->assertSuccessful();

    $cobranza = collect($response->json('data.cobranza'));
    expect($cobranza)->toHaveCount(30)
        ->and($cobranza->firstWhere('dia', 5)['total_cobrado'])->toEqual(150.0)
        ->and($cobranza->firstWhere('dia', 12)['total_cobrado'])->toEqual(200.0)
        ->and($cobranza->firstWhere('dia', 1)['total_cobrado'])->toEqual(0.0);
});

it('excludes anulado cobros from the cobranza total', function () {
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-05 10:00:00', 100);
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-05 11:00:00', 999, 'anulado');

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/cobranza-mensual?mes=2026-09')->assertSuccessful();

    expect(collect($response->json('data.cobranza'))->firstWhere('dia', 5)['total_cobrado'])->toEqual(100.0);
});

it('sums desembolsos per day and fills missing days with 0', function () {
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-09-05', 800);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-09-05', 200);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-09-20', 500);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/cobranza-mensual?mes=2026-09')->assertSuccessful();

    $desembolsos = collect($response->json('data.desembolsos'));
    expect($desembolsos)->toHaveCount(30)
        ->and($desembolsos->firstWhere('dia', 5)['total_desembolsado'])->toEqual(1000.0)
        ->and($desembolsos->firstWhere('dia', 20)['total_desembolsado'])->toEqual(500.0)
        ->and($desembolsos->firstWhere('dia', 1)['total_desembolsado'])->toEqual(0.0);
});

it('ignores a caja movimiento with concepto_id (not a desembolso)', function () {
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    $ciclo = CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $this->empresa->id, 'fecha' => '2026-09-05',
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => '2026-09-05',
    ]);

    CajaMovimiento::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'empresa_id' => $this->empresa->id,
        'tipo' => 'egreso',
        'concepto_id' => Concepto::factory()->create(['empresa_id' => $this->empresa->id])->id,
        'registrado_por' => $this->asesor->id,
        'monto' => 300,
        'fecha_caja' => '2026-09-05',
    ]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/cobranza-mensual?mes=2026-09')->assertSuccessful();

    expect(collect($response->json('data.desembolsos'))->firstWhere('dia', 5)['total_desembolsado'])->toEqual(0.0);
});

it('filters cobranza and desembolsos by agencia and by asesor', function () {
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

    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-05 10:00:00', 100);
    cobroEnFecha($this->empresa, $otroCredito, $otroCliente, $otroAsesor, '2026-09-05 10:00:00', 300);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-09-05', 1000);
    desembolsoEnFecha($this->empresa, $otraAgencia, $otroCredito, $otroAsesor, '2026-09-05', 3000);

    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $porAgencia = $this->getJson("/api/reportes/cobranza-mensual?mes=2026-09&agencia_id={$this->agencia->id}")->assertSuccessful();
    expect(collect($porAgencia->json('data.cobranza'))->firstWhere('dia', 5)['total_cobrado'])->toEqual(100.0)
        ->and(collect($porAgencia->json('data.desembolsos'))->firstWhere('dia', 5)['total_desembolsado'])->toEqual(1000.0);

    $porAsesor = $this->getJson("/api/reportes/cobranza-mensual?mes=2026-09&asesor_id={$otroAsesor->id}")->assertSuccessful();
    expect(collect($porAsesor->json('data.cobranza'))->firstWhere('dia', 5)['total_cobrado'])->toEqual(300.0)
        ->and(collect($porAsesor->json('data.desembolsos'))->firstWhere('dia', 5)['total_desembolsado'])->toEqual(3000.0);
});

it('breaks down cobranza and desembolsos by asesor, for the monthly and the annual endpoint', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCredito = Credito::factory()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'registrado_por' => $otroAsesor->id,
    ]);

    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-05 10:00:00', 100);
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-12 10:00:00', 50);
    cobroEnFecha($this->empresa, $otroCredito, $this->cliente, $otroAsesor, '2026-09-05 10:00:00', 300);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-09-05', 1000);
    desembolsoEnFecha($this->empresa, $this->agencia, $otroCredito, $otroAsesor, '2026-09-05', 3000);

    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $mensual = $this->getJson('/api/reportes/cobranza-mensual?mes=2026-09')->assertSuccessful();
    $cobranzaPorAsesor = collect($mensual->json('data.cobranzaPorAsesor'));
    $desembolsosPorAsesor = collect($mensual->json('data.desembolsosPorAsesor'));
    expect($cobranzaPorAsesor)->toHaveCount(2)
        ->and($cobranzaPorAsesor->firstWhere('asesor_id', $this->asesor->id))
        ->toMatchArray(['asesor_nombre' => trim("{$this->asesor->nombre} {$this->asesor->apellido}"), 'total_cobrado' => 150.0])
        ->and($cobranzaPorAsesor->firstWhere('asesor_id', $otroAsesor->id)['total_cobrado'])->toEqual(300.0)
        ->and($desembolsosPorAsesor->firstWhere('asesor_id', $this->asesor->id)['total_desembolsado'])->toEqual(1000.0)
        ->and($desembolsosPorAsesor->firstWhere('asesor_id', $otroAsesor->id)['total_desembolsado'])->toEqual(3000.0);

    $anual = $this->getJson('/api/reportes/cobranza-mensual/anual?anio=2026')->assertSuccessful();
    expect(collect($anual->json('data.cobranzaPorAsesor'))->firstWhere('asesor_id', $this->asesor->id)['total_cobrado'])->toEqual(150.0)
        ->and(collect($anual->json('data.desembolsosPorAsesor'))->firstWhere('asesor_id', $otroAsesor->id)['total_desembolsado'])->toEqual(3000.0);
});

it('lets sistemas filter cobranza and desembolsos by empresa_id and see every empresa when omitted', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');

    $otraEmpresa = Empresa::factory()->create();
    $otraAgencia = Agencia::factory()->for($otraEmpresa)->create();
    $otroCliente = Cliente::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor = User::factory()->forAgencia($otraAgencia)->create();
    $otroCredito = Credito::factory()->create([
        'empresa_id' => $otraEmpresa->id,
        'agencia_id' => $otraAgencia->id,
        'cliente_id' => $otroCliente->id,
        'registrado_por' => $otroAsesor->id,
    ]);

    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-09-05 10:00:00', 100);
    cobroEnFecha($otraEmpresa, $otroCredito, $otroCliente, $otroAsesor, '2026-09-05 10:00:00', 400);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-09-05', 1000);
    desembolsoEnFecha($otraEmpresa, $otraAgencia, $otroCredito, $otroAsesor, '2026-09-05', 4000);

    Sanctum::actingAs($sistemas, ['*']);

    $unaEmpresa = $this->getJson("/api/reportes/cobranza-mensual?mes=2026-09&empresa_id={$this->empresa->id}")->assertSuccessful();
    expect(collect($unaEmpresa->json('data.cobranza'))->firstWhere('dia', 5)['total_cobrado'])->toEqual(100.0)
        ->and(collect($unaEmpresa->json('data.desembolsos'))->firstWhere('dia', 5)['total_desembolsado'])->toEqual(1000.0);

    $todas = $this->getJson('/api/reportes/cobranza-mensual?mes=2026-09')->assertSuccessful();
    expect(collect($todas->json('data.cobranza'))->firstWhere('dia', 5)['total_cobrado'])->toEqual(500.0)
        ->and(collect($todas->json('data.desembolsos'))->firstWhere('dia', 5)['total_desembolsado'])->toEqual(5000.0);
});

it('sums cobranza and desembolsos per month across the year and fills missing months with 0', function () {
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-01-10 10:00:00', 100);
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-01-15 10:00:00', 50);
    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-06-01 10:00:00', 200);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-01-10', 800);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-01-15', 200);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-06-01', 500);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/cobranza-mensual/anual?anio=2026')->assertSuccessful();

    $cobranza = collect($response->json('data.cobranza'));
    $desembolsos = collect($response->json('data.desembolsos'));
    expect($cobranza)->toHaveCount(12)
        ->and($cobranza->firstWhere('mes', 1)['total_cobrado'])->toEqual(150.0)
        ->and($cobranza->firstWhere('mes', 6)['total_cobrado'])->toEqual(200.0)
        ->and($cobranza->firstWhere('mes', 3)['total_cobrado'])->toEqual(0.0)
        ->and($desembolsos)->toHaveCount(12)
        ->and($desembolsos->firstWhere('mes', 1)['total_desembolsado'])->toEqual(1000.0)
        ->and($desembolsos->firstWhere('mes', 6)['total_desembolsado'])->toEqual(500.0)
        ->and($desembolsos->firstWhere('mes', 3)['total_desembolsado'])->toEqual(0.0);
});

it('filters annual cobranza and desembolsos by agencia, asesor and empresa (sistemas)', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');

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

    cobroEnFecha($this->empresa, $this->credito, $this->cliente, $this->asesor, '2026-03-05 10:00:00', 100);
    cobroEnFecha($this->empresa, $otroCredito, $otroCliente, $otroAsesor, '2026-03-05 10:00:00', 300);
    desembolsoEnFecha($this->empresa, $this->agencia, $this->credito, $this->asesor, '2026-03-05', 1000);
    desembolsoEnFecha($this->empresa, $otraAgencia, $otroCredito, $otroAsesor, '2026-03-05', 3000);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $porAgencia = $this->getJson("/api/reportes/cobranza-mensual/anual?anio=2026&agencia_id={$this->agencia->id}")->assertSuccessful();
    expect(collect($porAgencia->json('data.cobranza'))->firstWhere('mes', 3)['total_cobrado'])->toEqual(100.0)
        ->and(collect($porAgencia->json('data.desembolsos'))->firstWhere('mes', 3)['total_desembolsado'])->toEqual(1000.0);

    Sanctum::actingAs($sistemas, ['*']);
    $todas = $this->getJson('/api/reportes/cobranza-mensual/anual?anio=2026')->assertSuccessful();
    expect(collect($todas->json('data.cobranza'))->firstWhere('mes', 3)['total_cobrado'])->toEqual(400.0)
        ->and(collect($todas->json('data.desembolsos'))->firstWhere('mes', 3)['total_desembolsado'])->toEqual(4000.0);
});

it('denies access to administrador_agencia, supervisor and asesor', function () {
    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');

    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');

    foreach ([$administradorAgencia, $supervisor, $this->asesor] as $user) {
        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/reportes/cobranza-mensual')->assertForbidden();
        $this->getJson('/api/reportes/cobranza-mensual/anual')->assertForbidden();
    }
});
