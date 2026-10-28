<?php

use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

// assertTotal(), cicloDe(), créditoDe(), clienteEn(), cobroHoy(), ventaDe(),
// pagoVentaHoy(), movimientoHoy(), desembolsoHoy() y egresoManualHoy() viven
// en tests/Pest.php: las comparte con DashboardGraficoCobranzaDesembolsoTest.

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->otraAgencia = Agencia::factory()->for($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $this->otroAsesor->assignRole('asesor');

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');

    // Cajas ya abiertas por usuario: cajas.user_id es UNIQUE, así que dos
    // movimientos del mismo asesor tienen que compartir ciclo en vez de crear
    // uno nuevo cada vez.
    $this->ciclo = cicloDe($this->asesor, $this->empresa, $this->agencia);
});

// creditoVencidoDe() vive en tests/Pest.php (compartida con
// DashboardCarteraActivaPorTipoTest, que también necesita créditos activos).

it('sums the cobranzas and desembolsos of the day', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    cobroHoy([], $credito, $cliente, $this->asesor, '250.00');
    cobroHoy([], $credito, $cliente, $this->asesor, '150.00');
    desembolsoHoy($credito, $this->asesor, '300.00');

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(400.00, $data['cobranza']['total']);
    assertTotal(300.00, $data['desembolso']['total']);
    assertTotal(100.00, $data['neto']);

    expect($data['cobranza']['cantidad'])->toBe(2)
        ->and($data['desembolso']['cantidad'])->toBe(1)
        ->and($data['fecha'])->toBe(now()->toDateString());
});

it('ignores cobros from other days', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    cobroHoy(['created_at' => now()->subDay()], $credito, $cliente, $this->asesor, '999.00');
    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(100.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.cobranza.total'));
});

it('excludes anulados from the cobranza total', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');
    cobroHoy(['estado' => 'anulado'], $credito, $cliente, $this->asesor, '500.00');

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(100.00, $data['cobranza']['total']);
    expect($data['cobranza']['cantidad'])->toBe(1);
});

it('does not count a manual egreso as a desembolso, and reports it separately as egresos', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    desembolsoHoy($credito, $this->asesor, '300.00');

    egresoManualHoy($this->asesor, '777.00');

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(300.00, $data['desembolso']['total']);
    assertTotal(777.00, $data['egresos']['total']);
    expect($data['egresos']['cantidad'])->toBe(1);
});

it('sums several egresos manuales of the day and ignores other days', function () {
    egresoManualHoy($this->asesor, '100.00');
    egresoManualHoy($this->asesor, '50.00');
    $otroDia = movimientoHoy($this->asesor, 'egreso', '999.00');
    CajaMovimiento::query()->where('id', $otroDia->id)->update(['created_at' => now()->subDay()]);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(150.00, $data['egresos']['total']);
    expect($data['egresos']['cantidad'])->toBe(2);
});

/**
 * Ingreso manual (módulo Ingresos: tipo=ingreso CON concepto del catálogo —
 * ver CajaMovimiento::scopeIngresosManuales()), a diferencia de
 * movimientoHoy()/un cobro/un pago de venta, que nunca llevan concepto_id.
 */
function ingresoManualHoy(User $registradoPor, string $monto): CajaMovimiento
{
    $concepto = Concepto::factory()->paraEmpresa(test()->empresa)->create(['tipo' => 'ingreso']);

    return CajaMovimiento::query()->create([
        'caja_ciclo_id' => test()->ciclo->id,
        'empresa_id' => test()->ciclo->empresa_id,
        'tipo' => 'ingreso',
        'concepto_id' => $concepto->id,
        'registrado_por' => $registradoPor->id,
        'monto' => $monto,
        'fecha_caja' => test()->ciclo->fecha,
        'created_at' => now(),
    ]);
}

it('sums ingresos manuales of the day, separate from cobranza (a cobro also writes tipo=ingreso, but without concepto_id), and ignores other days', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);
    cobroHoy([], $credito, $cliente, $this->asesor, '300.00');

    ingresoManualHoy($this->asesor, '500.00');
    ingresoManualHoy($this->asesor, '200.00');
    $otroDia = ingresoManualHoy($this->asesor, '999.00');
    CajaMovimiento::query()->where('id', $otroDia->id)->update(['created_at' => now()->subDay()]);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(700.00, $data['ingresos']['total']);
    expect($data['ingresos']['cantidad'])->toBe(2);
    // El cobro cuenta en cobranza, no en ingresos: no se duplica.
    assertTotal(300.00, $data['cobranza']['total']);
});

it('filters ingresos by agencia and asesor, without widening what the actor can see', function () {
    $otroAsesor = User::factory()->forAgencia($this->otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCiclo = cicloDe($otroAsesor, $this->empresa, $this->otraAgencia);
    $conceptoOtra = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'ingreso']);

    ingresoManualHoy($this->asesor, '100.00');
    CajaMovimiento::query()->create([
        'caja_ciclo_id' => $otroCiclo->id,
        'empresa_id' => $otroCiclo->empresa_id,
        'tipo' => 'ingreso',
        'concepto_id' => $conceptoOtra->id,
        'registrado_por' => $otroAsesor->id,
        'monto' => '900.00',
        'fecha_caja' => $otroCiclo->fecha,
        'created_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(100.00, $this->getJson("/api/dashboard/resumen?agencia_id={$this->agencia->id}")
        ->assertSuccessful()->json('data.ingresos.total'));

    assertTotal(100.00, $this->getJson("/api/dashboard/resumen?asesor_id={$this->asesor->id}")
        ->assertSuccessful()->json('data.ingresos.total'));

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    assertTotal(0.00, $this->getJson("/api/dashboard/resumen?agencia_id={$this->otraAgencia->id}")
        ->assertSuccessful()->json('data.ingresos.total'));
});

/**
 * Billetaje aprobado en $ciclo (dinero que esa caja recibió de la bóveda).
 * `registrado_por` es $aprobador (quien lo aprobó, no quien lo recibe) —
 * mismo criterio que BilletajeService::aprobarEnEfectivo().
 */
function billetajeHoy(CajaCiclo $ciclo, string $monto, User $aprobador): CajaMovimiento
{
    return CajaMovimiento::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'empresa_id' => $ciclo->empresa_id,
        'tipo' => 'billetaje',
        'monto' => $monto,
        'registrado_por' => $aprobador->id,
        'fecha_caja' => $ciclo->fecha,
        'created_at' => now(),
    ]);
}

it('adds billetaje recibido to the neto, on top of cobranza minus desembolso', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);
    cobroHoy([], $credito, $cliente, $this->asesor, '300.00');
    desembolsoHoy($credito, $this->asesor, '900.00');
    billetajeHoy($this->ciclo, '900.00', $this->admin);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(900.00, $data['billetajes']['total']);
    expect($data['billetajes']['cantidad'])->toBe(1);
    // Sin el billetaje el neto sería -600 (300 - 900); con él, el billetaje
    // cubrió el desembolso y el neto queda en lo que de verdad se cobró.
    assertTotal(300.00, $data['neto']);
});

it('attributes billetaje to the asesor who received it (the caja owner), not to the aprobador', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCiclo = cicloDe($otroAsesor, $this->empresa, $this->agencia);

    billetajeHoy($this->ciclo, '500.00', $this->admin);
    billetajeHoy($otroCiclo, '800.00', $this->admin);

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(500.00, $this->getJson("/api/dashboard/resumen?asesor_id={$this->asesor->id}")
        ->assertSuccessful()->json('data.billetajes.total'));

    assertTotal(800.00, $this->getJson("/api/dashboard/resumen?asesor_id={$otroAsesor->id}")
        ->assertSuccessful()->json('data.billetajes.total'));

    // Filtrar por el aprobador (administrador) no debe devolver nada: no es
    // "su" billetaje, el dueño de la caja es quien lo recibió.
    assertTotal(0.00, $this->getJson("/api/dashboard/resumen?asesor_id={$this->admin->id}")
        ->assertSuccessful()->json('data.billetajes.total'));
});

it('filters billetajes by agencia, without widening what the actor can see', function () {
    $otroAsesor = User::factory()->forAgencia($this->otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCiclo = cicloDe($otroAsesor, $this->empresa, $this->otraAgencia);

    billetajeHoy($this->ciclo, '100.00', $this->admin);
    billetajeHoy($otroCiclo, '900.00', $this->admin);

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    assertTotal(100.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.billetajes.total'));

    assertTotal(0.00, $this->getJson("/api/dashboard/resumen?agencia_id={$this->otraAgencia->id}")
        ->assertSuccessful()->json('data.billetajes.total'));
});

it('limits billetajes to the selected dates and includes digital receipts regardless of credit type', function () {
    billetajeHoy($this->ciclo, '120.00', $this->admin);
    billetajeHoy($this->ciclo, '80.00', $this->admin)->update(['medio' => 'cuenta_bancaria']);
    $anterior = billetajeHoy($this->ciclo, '999.00', $this->admin);
    CajaMovimiento::query()->whereKey($anterior->id)->update(['created_at' => now()->subDay()]);

    Sanctum::actingAs($this->admin, ['*']);
    $hoy = now()->toDateString();
    $ayer = now()->subDay()->toDateString();

    $data = $this->getJson("/api/dashboard/resumen?desde={$hoy}&hasta={$hoy}&tipo_credito=diario")
        ->assertSuccessful()->json('data');

    assertTotal(200.00, $data['billetajes']['total']);
    expect($data['billetajes']['cantidad'])->toBe(2);
    assertTotal(200.00, $data['neto']);
    assertTotal(999.00, $this->getJson("/api/dashboard/resumen?desde={$ayer}&hasta={$ayer}")
        ->assertSuccessful()->json('data.billetajes.total'));
});

it('only exposes the asesors own received billetajes even when another asesor is requested', function () {
    $otroCiclo = cicloDe($this->otroAsesor, $this->empresa, $this->agencia);
    billetajeHoy($this->ciclo, '100.00', $this->admin);
    billetajeHoy($otroCiclo, '900.00', $this->admin);
    Sanctum::actingAs($this->asesor, ['*']);

    assertTotal(100.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.billetajes.total'));
    assertTotal(0.00, $this->getJson("/api/dashboard/resumen?asesor_id={$this->otroAsesor->id}")
        ->assertSuccessful()->json('data.billetajes.total'));
});

it('filters egresos by agencia (via the caja that recorded them)', function () {
    $otroAsesor = User::factory()->forAgencia($this->otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCiclo = cicloDe($otroAsesor, $this->empresa, $this->otraAgencia);

    egresoManualHoy($this->asesor, '100.00');
    CajaMovimiento::query()->create([
        'caja_ciclo_id' => $otroCiclo->id,
        'empresa_id' => $otroCiclo->empresa_id,
        'tipo' => 'egreso',
        'concepto_id' => null,
        'credito_id' => null,
        'registrado_por' => $otroAsesor->id,
        'monto' => '900.00',
        'fecha_caja' => $otroCiclo->fecha,
        'created_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(100.00, $this->getJson("/api/dashboard/resumen?agencia_id={$this->agencia->id}")
        ->assertSuccessful()
        ->json('data.egresos.total'));
});

it('filters egresos by the asesor who registered them', function () {
    egresoManualHoy($this->asesor, '100.00');
    egresoManualHoy($this->otroAsesor, '900.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(100.00, $this->getJson("/api/dashboard/resumen?asesor_id={$this->asesor->id}")
        ->assertSuccessful()
        ->json('data.egresos.total'));
});

it('filters by agencia', function () {
    $clienteA = clienteEn($this->agencia);
    $clienteB = clienteEn($this->otraAgencia);

    cobroHoy([], créditoDe($clienteA), $clienteA, $this->asesor, '100.00');
    cobroHoy([], créditoDe($clienteB), $clienteB, $this->asesor, '900.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(100.00, $this->getJson("/api/dashboard/resumen?agencia_id={$this->agencia->id}")
        ->assertSuccessful()
        ->json('data.cobranza.total'));
});

it('filters by the asesor who collected', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');
    cobroHoy([], $credito, $cliente, $this->otroAsesor, '700.00');

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson("/api/dashboard/resumen?asesor_id={$this->asesor->id}")
        ->assertSuccessful()
        ->json('data');

    assertTotal(100.00, $data['cobranza']['total']);
    expect($data['cobranza']['cantidad'])->toBe(1);
});

it('filters by tipo de credito', function () {
    $cliente = clienteEn($this->agencia);

    cobroHoy([], créditoDe($cliente, 'prendario'), $cliente, $this->asesor, '100.00');
    cobroHoy([], créditoDe($cliente, 'hipotecario'), $cliente, $this->asesor, '400.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(400.00, $this->getJson('/api/dashboard/resumen?tipo_credito=hipotecario')
        ->assertSuccessful()
        ->json('data.cobranza.total'));

    assertTotal(100.00, $this->getJson('/api/dashboard/resumen?tipo_credito=prendario')
        ->assertSuccessful()
        ->json('data.cobranza.total'));
});

it('adds the venta payments to the cobranza total', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);
    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');

    $venta = ventaDe($cliente, $this->asesor, $this->agencia);

    pagoVentaHoy($venta, $this->asesor, '50.00');

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(150.00, $data['cobranza']['total']);
    expect($data['cobranza']['cantidad'])->toBe(2);

    // Con el filtro de ventas solo se leen los pagos de venta.
    $soloVentas = $this->getJson('/api/dashboard/resumen?tipo_credito=ventas')
        ->assertSuccessful()
        ->json('data');

    assertTotal(50.00, $soloVentas['cobranza']['total']);
    expect($soloVentas['cobranza']['cantidad'])->toBe(1);
});

it('excludes anulled venta payments', function () {
    $cliente = clienteEn($this->agencia);
    $venta = ventaDe($cliente, $this->asesor, $this->agencia);

    pagoVentaHoy($venta, $this->asesor, '80.00', anulada: true);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(0.00, $data['cobranza']['total']);
    expect($data['cobranza']['cantidad'])->toBe(0);
});

it('does not leak to an asesor the cobros of a colleague on a credit they cannot see', function () {
    // El scoping del asesor mira credito.registrado_por. Un crédito de otro
    // asesor queda fuera aunque el cobro lo haya hecho este mismo asesor.
    $clienteAjeno = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->otroAsesor)->create([
        'empresa_id' => $this->empresa->id,
    ]);

    $creditoAjeno = créditoDe($clienteAjeno, registradoPor: $this->otroAsesor);

    // El asesor registra el cobro, pero sobre el crédito del colega.
    cobroHoy([], $creditoAjeno, $clienteAjeno, $this->asesor, '900.00');

    Sanctum::actingAs($this->asesor, ['*']);

    assertTotal(0.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.cobranza.total'));
});

it('shows an asesor only their own operation', function () {
    $clientePropio = clienteEn($this->agencia);
    $clienteAjeno = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->otroAsesor)->create([
        'empresa_id' => $this->empresa->id,
    ]);

    // Cada crédito lo registra su propio asesor: el scoping del asesor se apoya
    // en credito.registrado_por, no en quién hizo el cobro.
    cobroHoy([], créditoDe($clientePropio, registradoPor: $this->asesor), $clientePropio, $this->asesor, '100.00');
    cobroHoy([], créditoDe($clienteAjeno, registradoPor: $this->otroAsesor), $clienteAjeno, $this->otroAsesor, '900.00');

    Sanctum::actingAs($this->asesor, ['*']);

    assertTotal(100.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.cobranza.total'));
});

it('shows administrador_agencia only its own agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    $clienteA = clienteEn($this->agencia);
    $clienteB = clienteEn($this->otraAgencia);

    cobroHoy([], créditoDe($clienteA), $clienteA, $this->asesor, '100.00');
    cobroHoy([], créditoDe($clienteB), $clienteB, $this->asesor, '900.00');

    Sanctum::actingAs($adminAgencia, ['*']);

    assertTotal(100.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.cobranza.total'));
});

it('does not let a filter widen what the actor can see', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    $clienteB = clienteEn($this->otraAgencia);
    cobroHoy([], créditoDe($clienteB), $clienteB, $this->asesor, '900.00');

    Sanctum::actingAs($adminAgencia, ['*']);

    // Pide explícitamente la otra agencia: el scoping por rol manda.
    assertTotal(0.00, $this->getJson("/api/dashboard/resumen?agencia_id={$this->otraAgencia->id}")
        ->assertSuccessful()
        ->json('data.cobranza.total'));
});

it('denies access without the dashboard.ver permission', function () {
    $sinRol = User::factory()->forEmpresa($this->empresa)->create();

    Sanctum::actingAs($sinRol, ['*']);

    $this->getJson('/api/dashboard/resumen')->assertForbidden();
});

it('denies acceso to peinadora which has no cobranzas role', function () {
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');

    expect($peinadora->can('dashboard.ver'))->toBeFalse();

    Sanctum::actingAs($peinadora, ['*']);
    $this->getJson('/api/dashboard/resumen')->assertForbidden();
});

it('rejects an invalid tipo_credito', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/dashboard/resumen?tipo_credito=inventado')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tipo_credito');
});

it('returns zeroed totals when there is no movement at all', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(0.00, $data['cobranza']['total']);
    assertTotal(0.00, $data['desembolso']['total']);
    assertTotal(0.00, $data['neto']);

    expect($data['cobranza']['cantidad'])->toBe(0)
        ->and($data['desembolso']['cantidad'])->toBe(0)
        ->and($data['egresos']['cantidad'])->toBe(0)
        ->and($data['ingresos']['cantidad'])->toBe(0)
        ->and($data['vencidos']['cantidad'])->toBe(0);

    assertTotal(0.00, $data['egresos']['total']);
    assertTotal(0.00, $data['ingresos']['total']);
    assertTotal(0.00, $data['vencidos']['total']);
});

it('reports a negative neto when desembolsos exceed cobranzas', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');
    desembolsoHoy($credito, $this->asesor, '900.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(-800.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.neto'));
});

it('sums the pending saldo of vencido créditos, not a flat count', function () {
    $cliente = clienteEn($this->agencia);

    creditoVencidoDe($cliente, '1000.00', '600.00'); // saldo 400
    creditoVencidoDe($cliente, '500.00', '0.00'); // saldo 500
    créditoDe($cliente); // activo, no debe entrar

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(900.00, $data['vencidos']['total']);
    expect($data['vencidos']['cantidad'])->toBe(2);
});

it('never reports a negative saldo for an overpaid vencido crédito', function () {
    $cliente = clienteEn($this->agencia);

    creditoVencidoDe($cliente, '100.00', '150.00');

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    assertTotal(0.00, $data['vencidos']['total']);
});

it('filters vencidos by agencia_id, asesor_id and tipo_credito', function () {
    $clienteDelAsesor = Cliente::factory()->asignadoA($this->asesor)->create();
    $clienteDelOtroAsesor = Cliente::factory()->asignadoA($this->otroAsesor)->create();
    $clienteB = clienteEn($this->otraAgencia);

    creditoVencidoDe($clienteDelAsesor, '400.00', '0.00', 'prendario');
    creditoVencidoDe($clienteDelOtroAsesor, '600.00', '0.00', 'hipotecario');
    creditoVencidoDe($clienteB, '900.00', '0.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(1000.00, $this->getJson("/api/dashboard/resumen?agencia_id={$this->agencia->id}")
        ->assertSuccessful()->json('data.vencidos.total'));

    assertTotal(400.00, $this->getJson("/api/dashboard/resumen?asesor_id={$this->asesor->id}")
        ->assertSuccessful()->json('data.vencidos.total'));

    assertTotal(600.00, $this->getJson('/api/dashboard/resumen?tipo_credito=hipotecario')
        ->assertSuccessful()->json('data.vencidos.total'));
});

it('does not let a vencidos filter widen what an administrador_agencia can see', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    creditoVencidoDe(clienteEn($this->otraAgencia), '900.00', '0.00');

    Sanctum::actingAs($adminAgencia, ['*']);

    assertTotal(0.00, $this->getJson("/api/dashboard/resumen?agencia_id={$this->otraAgencia->id}")
        ->assertSuccessful()->json('data.vencidos.total'));
});

it('defaults desde/hasta to today and echoes them back', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');

    expect($data['desde'])->toBe(now()->toDateString())
        ->and($data['hasta'])->toBe(now()->toDateString())
        ->and($data['fecha'])->toBe(now()->toDateString());
});

it('sums cobranza, desembolso and egresos over the whole desde/hasta range, not just today', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    $ayer = cobroHoy(['created_at' => now()->subDay()], $credito, $cliente, $this->asesor, '200.00');
    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');

    $desembolsoAyer = desembolsoHoy($credito, $this->asesor, '300.00');
    CajaMovimiento::query()->where('id', $desembolsoAyer->id)->update(['created_at' => now()->subDay()]);

    $egresoAyer = egresoManualHoy($this->asesor, '50.00');
    CajaMovimiento::query()->where('id', $egresoAyer->id)->update(['created_at' => now()->subDay()]);

    Sanctum::actingAs($this->admin, ['*']);

    $desde = now()->subDay()->toDateString();
    $hasta = now()->toDateString();

    $data = $this->getJson("/api/dashboard/resumen?desde={$desde}&hasta={$hasta}")->assertSuccessful()->json('data');

    assertTotal(300.00, $data['cobranza']['total']);
    assertTotal(300.00, $data['desembolso']['total']);
    assertTotal(50.00, $data['egresos']['total']);
    expect($data['desde'])->toBe($desde)->and($data['hasta'])->toBe($hasta);

    // Un solo día (hoy) sigue dejando fuera lo de ayer, igual que antes de que
    // el rango existiera.
    $soloHoy = $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data');
    assertTotal(100.00, $soloHoy['cobranza']['total']);
    assertTotal(0.00, $soloHoy['desembolso']['total']);
});

it('does not let desde/hasta affect vencidos, which is the cartera right now', function () {
    $cliente = clienteEn($this->agencia);
    creditoVencidoDe($cliente, '1000.00', '600.00');

    Sanctum::actingAs($this->admin, ['*']);

    $desde = now()->subYear()->toDateString();
    $hasta = now()->subYear()->toDateString();

    assertTotal(400.00, $this->getJson("/api/dashboard/resumen?desde={$desde}&hasta={$hasta}")
        ->assertSuccessful()->json('data.vencidos.total'));
});

it('rejects hasta before desde', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $desde = now()->toDateString();
    $hasta = now()->subDay()->toDateString();

    $this->getJson("/api/dashboard/resumen?desde={$desde}&hasta={$hasta}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hasta');
});
