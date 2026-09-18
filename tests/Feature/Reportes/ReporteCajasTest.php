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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('public');

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->conceptoIngreso = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'ingreso']);
    $this->conceptoGasto = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto']);
});

/**
 * Aperture la caja del asesor, agrega un ingreso/egreso manual, un cobro
 * (efectivo), un billetaje aprobado y un "desembolso" (egreso sin concepto
 * de catálogo, ligado a un crédito real) directos por creación explícita
 * sobre el mismo ciclo, y cierra.
 *
 * @return array{ciclo: CajaCiclo, cliente: Cliente, credito: Credito}
 */
function prepararCicloDe(User $asesor, Concepto $conceptoIngreso, Concepto $conceptoGasto): array
{
    Sanctum::actingAs($asesor, ['*']);
    test()->postJson('/api/caja/aperturar')->assertCreated();

    test()->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso', 'concepto_id' => $conceptoIngreso->id, 'monto' => 100,
    ])->assertCreated();

    test()->postJson('/api/caja/movimientos', [
        'tipo' => 'egreso', 'concepto_id' => $conceptoGasto->id, 'monto' => 30,
        'comprobante' => UploadedFile::fake()->image('comprobante.jpg'),
    ])->assertCreated();

    $ciclo = Caja::where('user_id', $asesor->id)->firstOrFail()->cicloAbierto()->firstOrFail();

    $cliente = Cliente::factory()->create(['empresa_id' => $asesor->empresa_id, 'agencia_id' => $asesor->agencia_id]);

    // Un cobro siempre trae consigo su propio CajaMovimiento de ingreso (ver
    // CreditoService::registrarCobroEnCaja()) — al crear el Cobro directo
    // por fuera de ese flujo, hay que recrearlo también.
    $movimientoCobro = CajaMovimiento::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'empresa_id' => $ciclo->empresa_id,
        'tipo' => 'ingreso',
        'monto' => 200,
        'concepto' => 'Cobro de prueba',
        'registrado_por' => $asesor->id,
        'fecha_caja' => $ciclo->fecha,
    ]);

    Cobro::factory()->create([
        'empresa_id' => $asesor->empresa_id,
        'caja_ciclo_id' => $ciclo->id,
        'caja_movimiento_id' => $movimientoCobro->id,
        'cliente_id' => $cliente->id,
        'monto_pagado' => 200,
        'medio' => 'efectivo',
    ]);

    // Billetaje::factory()'s definition() unconditionally creates a
    // throwaway CajaCiclo (whose own CajaFactory chain creates a bare User
    // without empresa_id) — same pre-existing factory quirk as
    // CajaMovimiento's below. Create the row directly instead.
    $boveda = Boveda::where('agencia_id', $asesor->agencia_id)->firstOrFail();
    $billetaje = Billetaje::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'boveda_id' => $boveda->id,
        'empresa_id' => $asesor->empresa_id,
        'monto' => 80,
        'estado' => 'aprobado',
        'motivo' => 'Vuelto insuficiente',
        'solicitado_por' => $asesor->id,
        'fecha_resolucion' => now(),
    ]);

    // Un billetaje aprobado siempre trae consigo su propio CajaMovimiento
    // (ver BilletajeService::aprobarEnEfectivo()) — al crear el Billetaje
    // directo por fuera de ese flujo, hay que recrearlo también.
    CajaMovimiento::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'empresa_id' => $ciclo->empresa_id,
        'tipo' => 'billetaje',
        'monto' => 80,
        'billetaje_id' => $billetaje->id,
        'registrado_por' => $asesor->id,
        'fecha_caja' => $ciclo->fecha,
    ]);

    $credito = Credito::factory()->create([
        'empresa_id' => $asesor->empresa_id,
        'agencia_id' => $asesor->agencia_id,
        'cliente_id' => $cliente->id,
        'registrado_por' => $asesor->id,
    ]);

    CajaMovimiento::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'empresa_id' => $ciclo->empresa_id,
        'tipo' => 'egreso',
        'monto' => 150,
        'concepto' => "Desembolso de crédito prendario #{$credito->id}",
        'credito_id' => $credito->id,
        'registrado_por' => $asesor->id,
        'fecha_caja' => $ciclo->fecha,
    ]);

    test()->postJson('/api/caja/cerrar', ['monto_contado' => 120])->assertSuccessful();

    return ['ciclo' => $ciclo->fresh(), 'cliente' => $cliente, 'credito' => $credito];
}

it('reports the totals of a closed ciclo, split by ingresos/egresos/billetaje/cobranza/desembolso', function () {
    prepararCicloDe($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    $response = $this->getJson('/api/reportes/cajas-apertura-cierre')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1);

    $fila = $response->json('data.0');

    expect($fila['estado'])->toBe('cerrada')
        ->and($fila['valor_aperturado'])->toBe('0.00')
        ->and($fila['valor_cerrado'])->toBe('120.00')
        ->and($fila['total_ingresos'])->toBe('100.00')
        ->and($fila['total_egresos'])->toBe('30.00')
        ->and($fila['total_billetaje'])->toBe('80.00')
        ->and($fila['total_cobranza'])->toBe('200.00')
        ->and($fila['total_desembolso'])->toBe('150.00')
        ->and($fila['usuario']['id'])->toBe($this->asesor->id);
});

it('returns the line-by-line detalle of a ciclo, grouped by tipo/medio, with the saldo calculation', function () {
    $preparado = prepararCicloDe($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    $response = $this->getJson("/api/reportes/cajas-apertura-cierre/{$preparado['ciclo']->id}/detalle")->assertSuccessful();
    $data = $response->json('data');

    expect($data['billetajes'])->toHaveCount(1)
        ->and($data['billetajes'][0]['motivo'])->toBe('Vuelto insuficiente')
        ->and($data['total_billetaje'])->toBe('80.00')
        ->and($data['ingresos'])->toHaveCount(1)
        ->and($data['total_ingresos'])->toBe('100.00')
        ->and($data['egresos'])->toHaveCount(1)
        ->and($data['total_egresos'])->toBe('30.00')
        ->and($data['cobranzas']['efectivo'])->toHaveCount(1)
        ->and($data['cobranzas']['efectivo'][0]['cliente']['id'])->toBe($preparado['cliente']->id)
        ->and($data['cobranzas']['yape'])->toHaveCount(0)
        ->and($data['cobranzas']['plin'])->toHaveCount(0)
        ->and($data['cobranzas']['transferencia'])->toHaveCount(0)
        ->and($data['totales_cobranza']['efectivo'])->toBe('200.00')
        ->and($data['desembolsos'])->toHaveCount(1)
        ->and($data['desembolsos'][0]['cliente']['id'])->toBe($preparado['cliente']->id)
        ->and($data['desembolsos'][0]['credito_id'])->toBe($preparado['credito']->id)
        ->and($data['total_desembolso'])->toBe('150.00')
        // SALDO TOTAL = billetaje(80) + ingresos(100) + cobranza(200) - egresos(30) - desembolso(150) = 200
        ->and($data['saldo_total'])->toBe('200.00')
        ->and($data['saldo_cierre'])->toBe('120.00')
        // DIFERENCIA = saldo_cierre(120) - saldo_total(200) = -80 (faltante)
        ->and($data['diferencia'])->toBe('-80.00');
});

it('denies the detalle of a ciclo outside the actor\'s visibility', function () {
    $preparado = prepararCicloDe($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroAsesor = User::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    Sanctum::actingAs($otroAsesor, ['*']);

    $this->getJson("/api/reportes/cajas-apertura-cierre/{$preparado['ciclo']->id}/detalle")->assertForbidden();
});

it('scopes an asesor to only their own caja ciclos', function () {
    prepararCicloDe($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    prepararCicloDe($otroAsesor, $this->conceptoIngreso, $this->conceptoGasto);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/reportes/cajas-apertura-cierre')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.usuario.id'))->toBe($this->asesor->id);
});

it('scopes administrador_agencia to only their own agencia', function () {
    prepararCicloDe($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroAsesor = User::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    prepararCicloDe($otroAsesor, $this->conceptoIngreso, $this->conceptoGasto);

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $response = $this->getJson('/api/reportes/cajas-apertura-cierre')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.usuario.id'))->toBe($this->asesor->id);
});

it('filters by estado', function () {
    prepararCicloDe($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->getJson('/api/reportes/cajas-apertura-cierre?estado=abierta')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.estado', 'abierta');

    $this->getJson('/api/reportes/cajas-apertura-cierre?estado=cerrada')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.estado', 'cerrada');
});

it('denies a role without cajas.ver', function () {
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');
    Sanctum::actingAs($peinadora, ['*']);

    $this->getJson('/api/reportes/cajas-apertura-cierre')->assertForbidden();
});

it('filters by fecha desde/hasta', function () {
    prepararCicloDe($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    $manana = now()->addDay()->toDateString();

    $this->getJson("/api/reportes/cajas-apertura-cierre?desde={$manana}")
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});
