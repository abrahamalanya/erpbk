<?php

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\PagoVenta;
use App\Modules\Venta\Models\Venta;
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

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');

    $this->concepto = Concepto::factory()->paraEmpresa($this->empresa)->create(['nombre' => 'Varios']);

    $this->credito = Credito::factory()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'registrado_por' => $this->asesor->id,
    ]);

    $this->ciclo = ie_cajaCicloDe($this->empresa, $this->agencia, $this->asesor);
});

/**
 * Prefijadas con ie_ porque ReporteFlujoCajaTest ya declara globalmente un
 * ie_cajaCicloDe() / movimiento() / cobroEnCaja() y PHP no admite repetidos.
 *
 * Caja::user_id es único — firstOrCreate reusa la caja/ciclo del usuario
 * entre llamadas del mismo test.
 */
function ie_cajaCicloDe(Empresa $empresa, Agencia $agencia, User $user): CajaCiclo
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
 * Se arma con el modelo y no con CajaMovimientoFactory a propósito: su
 * definition() arma su propio CajaCiclo::factory(), que termina en un
 * Caja::factory() sin empresa y revienta el NOT NULL, aunque después se pase el
 * ciclo correcto por el state.
 *
 * @param  array<string, mixed>  $extra
 */
function ie_movimiento(CajaCiclo $ciclo, Empresa $empresa, string $tipo, float $monto, string $fecha, User $registradoPor, array $extra = []): CajaMovimiento
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
 * Un cobro con su movimiento de caja espejo — el par que CreditoService siempre
 * escribe junto (registrarCobroEnCaja()).
 */
function ie_cobroEnCaja(CajaCiclo $ciclo, Empresa $empresa, Credito $credito, Cliente $cliente, User $asesor, float $monto, string $fecha, string $estado = 'registrado'): Cobro
{
    $movimiento = ie_movimiento($ciclo, $empresa, 'ingreso', $monto, $fecha, $asesor, [
        'concepto' => "Refrendo de crédito #{$credito->id}",
    ]);

    return Cobro::query()->create([
        'empresa_id' => $empresa->id,
        'cliente_id' => $cliente->id,
        'credito_id' => $credito->id,
        'caja_ciclo_id' => $ciclo->id,
        'caja_movimiento_id' => $movimiento->id,
        'registrado_por' => $asesor->id,
        'operacion' => 'refrendo',
        'estado' => $estado,
        'monto_pagado' => $monto,
        'medio' => 'efectivo',
        'interes' => 0,
        'vuelto' => 0,
    ]);
}

/**
 * Un pago de venta con su movimiento de caja espejo, igual que
 * VentaService::registrarPago().
 */
function ie_pagoVenta(CajaCiclo $ciclo, Empresa $empresa, Venta $venta, User $asesor, float $monto, string $fecha, string $tipo = 'inicial'): PagoVenta
{
    $movimiento = ie_movimiento($ciclo, $empresa, 'ingreso', $monto, $fecha, $asesor, [
        'concepto' => "Venta #{$venta->id} — ".ucfirst($tipo),
        'venta_id' => $venta->id,
    ]);

    return PagoVenta::query()->create([
        'venta_id' => $venta->id,
        'empresa_id' => $empresa->id,
        'caja_ciclo_id' => $ciclo->id,
        'caja_movimiento_id' => $movimiento->id,
        'registrado_por' => $asesor->id,
        'tipo' => $tipo,
        'monto' => $monto,
        'medio' => 'efectivo',
    ]);
}

/**
 * Con el modelo y no con VentaFactory, por lo mismo que en
 * DashboardInicialTest: el factory genera su propio artículo, cliente y empresa
 * sueltos y termina chocando con los NOT NULL de la venta.
 */
function ie_ventaDe(Agencia $agencia, Cliente $cliente, User $vendedor, string $estado = 'activa'): Venta
{
    $articulo = Bien::factory()->paraCliente($cliente)->create(['estado' => 'disponible_venta']);

    return Venta::query()->create([
        'empresa_id' => $cliente->empresa_id,
        'agencia_id' => $agencia->id,
        'articulo_type' => $articulo->getMorphClass(),
        'articulo_id' => $articulo->id,
        'cliente_id' => $cliente->id,
        'vendido_por' => $vendedor->id,
        'forma_venta' => 'contado',
        'estado' => $estado,
        'precio_venta' => 500,
        'inicial' => 500,
        'saldo_pendiente' => 0,
        'pagada_at' => now(),
    ]);
}

it('breaks the money down into the 5 sections with their totals, utilidad and margen', function () {
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, ['concepto_id' => $this->concepto->id, 'concepto' => 'Varios']);
    ie_cobroEnCaja($this->ciclo, $this->empresa, $this->credito, $this->cliente, $this->asesor, 150, '2026-09-05');
    ie_pagoVenta($this->ciclo, $this->empresa, ie_ventaDe($this->agencia, $this->cliente, $this->asesor), $this->asesor, 200, '2026-09-05');
    ie_movimiento($this->ciclo, $this->empresa, 'egreso', 40, '2026-09-05', $this->asesor, ['concepto_id' => $this->concepto->id, 'concepto' => 'Útiles']);
    ie_movimiento($this->ciclo, $this->empresa, 'egreso', 500, '2026-09-05', $this->asesor, ['concepto' => 'Desembolso', 'credito_id' => $this->credito->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')->assertSuccessful();

    $totales = $response->json('data.totales');
    expect($totales['ingresos'])->toBe('100.00')
        ->and($totales['cobranzas'])->toBe('150.00')
        ->and($totales['ventas'])->toBe('200.00')
        ->and($totales['egresos'])->toBe('40.00')
        ->and($totales['desembolsos'])->toBe('500.00')
        // (100 + 150 + 200) - (40 + 500) = 450 - 540
        ->and($totales['total_ingresos'])->toBe('450.00')
        ->and($totales['total_egresos'])->toBe('540.00')
        ->and($response->json('data.utilidad_neta'))->toBe('-90.00')
        ->and($response->json('data.margen_utilidad_bruta'))->toEqual(0.8333);

    // Cada sección trae su detalle con las 4 columnas del enunciado.
    expect($response->json('data.ingresos.filas'))->toHaveCount(1)
        ->and($response->json('data.ingresos.filas.0.cuenta'))->toBe(trim($this->asesor->nombre.' '.$this->asesor->apellido))
        ->and($response->json('data.ingresos.filas.0.fecha'))->toBe('2026-09-05')
        ->and($response->json('data.ingresos.filas.0.monto'))->toBe('100.00');
});

it('never counts a cobro or a venta payment twice as ingresos', function () {
    ie_cobroEnCaja($this->ciclo, $this->empresa, $this->credito, $this->cliente, $this->asesor, 150, '2026-09-05');
    ie_pagoVenta($this->ciclo, $this->empresa, ie_ventaDe($this->agencia, $this->cliente, $this->asesor), $this->asesor, 200, '2026-09-05');

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $totales = $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')
        ->assertSuccessful()->json('data.totales');

    // Los dos movimientos tipo=ingreso que esos flujos generan NO llevan
    // concepto_id, así que no deben aparecer en la sección Ingresos.
    expect($totales['ingresos'])->toBe('0.00')
        ->and($totales['cobranzas'])->toBe('150.00')
        ->and($totales['ventas'])->toBe('200.00')
        ->and($totales['total_ingresos'])->toBe('350.00');
});

it('excludes el billetaje, que is cash delivered and not income', function () {
    $boveda = Boveda::factory()->principalDe($this->empresa)->create();
    $billetaje = Billetaje::query()->create([
        'caja_ciclo_id' => $this->ciclo->id,
        'boveda_id' => $boveda->id,
        'empresa_id' => $this->empresa->id,
        'monto' => 1000,
        'estado' => 'aprobado',
        'solicitado_por' => $this->asesor->id,
        'aprobado_por' => $this->administradorAgencia->id,
        'fecha_resolucion' => now()->toDateString(),
    ]);
    ie_movimiento($this->ciclo, $this->empresa, 'billetaje', 1000, '2026-09-05', $this->administradorAgencia, ['billetaje_id' => $billetaje->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $totales = $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')
        ->assertSuccessful()->json('data.totales');

    expect($totales['total_ingresos'])->toBe('0.00')
        ->and($totales['ingresos'])->toBe('0.00')
        ->and($totales['total_egresos'])->toBe('0.00');
});

it('excludes cobros anulados and pagos de una venta cancelada', function () {
    ie_cobroEnCaja($this->ciclo, $this->empresa, $this->credito, $this->cliente, $this->asesor, 150, '2026-09-05', 'anulado');
    ie_pagoVenta($this->ciclo, $this->empresa, ie_ventaDe($this->agencia, $this->cliente, $this->asesor, 'cancelada'), $this->asesor, 200, '2026-09-05');
    ie_cobroEnCaja($this->ciclo, $this->empresa, $this->credito, $this->cliente, $this->asesor, 50, '2026-09-06');

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $totales = $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')
        ->assertSuccessful()->json('data.totales');

    expect($totales['cobranzas'])->toBe('50.00')
        ->and($totales['ventas'])->toBe('0.00');
});

it('separates egresos manuales of desembolsos by their credito', function () {
    ie_movimiento($this->ciclo, $this->empresa, 'egreso', 40, '2026-09-05', $this->asesor, ['concepto_id' => $this->concepto->id, 'concepto' => 'Útiles']);
    ie_movimiento($this->ciclo, $this->empresa, 'egreso', 500, '2026-09-05', $this->asesor, ['concepto' => 'Desembolso', 'credito_id' => $this->credito->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $data = $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')->assertSuccessful()->json('data');

    expect($data['egresos']['filas'])->toHaveCount(1)
        ->and($data['egresos']['filas'][0]['monto'])->toBe('40.00')
        ->and($data['desembolsos']['filas'])->toHaveCount(1)
        ->and($data['desembolsos']['filas'][0]['monto'])->toBe('500.00')
        ->and($data['desembolsos']['filas'][0]['descripcion'])->toContain($this->cliente->nombre);
});

it('includes the boleta serie and numero in the descripcion when present', function () {
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, [
        'concepto_id' => $this->concepto->id,
        'concepto' => 'Varios',
        'descripcion' => 'Devolución de cajero',
        'serie' => 'B001',
        'numero' => '000123',
    ]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $fila = $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')
        ->assertSuccessful()->json('data.ingresos.filas.0');

    expect($fila['descripcion'])->toContain('Varios')
        ->and($fila['descripcion'])->toContain('Devolución de cajero')
        ->and($fila['descripcion'])->toContain('Boleta B001-000123')
        ->and($fila['serie'])->toBe('B001')
        ->and($fila['numero'])->toBe('000123');
});

it('filters by agencia', function () {
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, ['concepto_id' => $this->concepto->id]);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroCliente = Cliente::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor = User::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCiclo = ie_cajaCicloDe($this->empresa, $otraAgencia, $otroAsesor);
    ie_movimiento($otroCiclo, $this->empresa, 'ingreso', 999, '2026-09-05', $otroAsesor, ['concepto_id' => $this->concepto->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $porAgencia = $this->getJson("/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30&agencia_id={$this->agencia->id}")
        ->assertSuccessful()->json('data.totales.ingresos');
    expect($porAgencia)->toBe('100.00');

    $porOtra = $this->getJson("/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30&agencia_id={$otraAgencia->id}")
        ->assertSuccessful()->json('data.totales.ingresos');
    expect($porOtra)->toBe('999.00');
});

it('scopes visibility by role: asesor sees only their own caja, administrador_agencia only their agencia, administrador_general sees all', function () {
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, ['concepto_id' => $this->concepto->id]);

    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $otroCiclo = ie_cajaCicloDe($this->empresa, $this->agencia, $otroAsesor);
    ie_movimiento($otroCiclo, $this->empresa, 'ingreso', 50, '2026-09-05', $otroAsesor, ['concepto_id' => $this->concepto->id]);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $asesorOtra = User::factory()->forAgencia($otraAgencia)->create();
    $asesorOtra->assignRole('asesor');
    $cicloOtra = ie_cajaCicloDe($this->empresa, $otraAgencia, $asesorOtra);
    ie_movimiento($cicloOtra, $this->empresa, 'ingreso', 25, '2026-09-05', $asesorOtra, ['concepto_id' => $this->concepto->id]);

    $ingresosDe = function (User $actor): string {
        Sanctum::actingAs($actor, ['*']);

        return $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')
            ->assertSuccessful()->json('data.totales.ingresos');
    };

    expect($ingresosDe($this->asesor))->toBe('100.00')
        ->and($ingresosDe($this->administradorAgencia))->toBe('150.00')
        ->and($ingresosDe($this->administradorGeneral))->toBe('175.00');
});

it('uses who registered the movimiento as the cuenta, not the owner of the caja', function () {
    // El administrador general registra en la caja del asesor: la cuenta es él.
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->administradorGeneral, ['concepto_id' => $this->concepto->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    expect($this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')
        ->assertSuccessful()->json('data.ingresos.filas.0.cuenta'))
        ->toBe(trim($this->administradorGeneral->nombre.' '.$this->administradorGeneral->apellido));
});

it('returns a null margen and a positive utilidad when there are no egresos', function () {
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, ['concepto_id' => $this->concepto->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $data = $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')->assertSuccessful()->json('data');

    // Sin egresos no hay contra qué dividir el margen, y la utilidad es todo
    // lo que entró.
    expect($data['margen_utilidad_bruta'])->toBeNull()
        ->and($data['utilidad_neta'])->toBe('100.00');
});

it('defaults to the current month and only counts what falls in the range', function () {
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 100, now()->toDateString(), $this->asesor, ['concepto_id' => $this->concepto->id]);
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 700, now()->subMonths(3)->toDateString(), $this->asesor, ['concepto_id' => $this->concepto->id]);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $response = $this->getJson('/api/reportes/ingresos-egresos')->assertSuccessful();

    // Sin desde/hasta solo entra el mes en curso.
    expect($response->json('data.totales.ingresos'))->toBe('100.00');
});

it('rejects an invalid date range and an unknown agencia', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-30&hasta=2026-09-01')->assertUnprocessable();
    $this->getJson('/api/reportes/ingresos-egresos?agencia_id=99999')->assertUnprocessable();
    $this->getJson('/api/reportes/ingresos-egresos?desde=no-es-una-fecha')->assertUnprocessable();
});

it('denies the report to a role without the cajas.ver permission', function () {
    // El reporte se autoriza igual que "ver cajas", así que un rol que no
    // entra al módulo Caja queda fuera — mismo criterio que ReporteCajasTest.
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');

    expect($peinadora->can('cajas.ver'))->toBeFalse();

    Sanctum::actingAs($peinadora, ['*']);
    $this->getJson('/api/reportes/ingresos-egresos?desde=2026-09-01&hasta=2026-09-30')->assertForbidden();
});

it('exports the report to PDF and Excel', function () {
    ie_movimiento($this->ciclo, $this->empresa, 'ingreso', 100, '2026-09-05', $this->asesor, ['concepto_id' => $this->concepto->id, 'concepto' => 'Varios']);

    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->get('/api/reportes/ingresos-egresos/pdf?desde=2026-09-01&hasta=2026-09-30')
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');

    $this->get('/api/reportes/ingresos-egresos/excel?desde=2026-09-01&hasta=2026-09-30')
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});
