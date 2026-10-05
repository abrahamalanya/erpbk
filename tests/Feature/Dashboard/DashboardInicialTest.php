<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\PagoVenta;
use App\Modules\Venta\Models\Venta;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * El JSON de un 100.0 viaja como "100" y el de 400.50 como "400.5", así que
 * assertJsonPath con un float literal es frágil según el monto. Compara
 * siempre como número, sin importar si del JSON vuelve int o float.
 */
function assertTotal(float $esperado, mixed $real): void
{
    expect(round((float) $real, 2))->toBe(round($esperado, 2));
}

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

/**
 * Caja + ciclo abiertos de una vez: los movimientos de caja cuelgan de un
 * ciclo real (mismo montaje que usan los tests de Cobranza y Créditos).
 */
/**
 * Caja + ciclo abiertos de una vez, con empresa/agencia explícitas: es el
 * mismo montaje que usan los tests de Cobranza y Créditos (el usuario de test
 * no arrastra empresa y cajas.empresa_id es NOT NULL).
 */
function cicloDe(User $user, Empresa $empresa, Agencia $agencia): CajaCiclo
{
    $caja = Caja::factory()->create([
        'user_id' => $user->id,
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
    ]);

    return CajaCiclo::query()->create([
        'caja_id' => $caja->id,
        'empresa_id' => $caja->empresa_id,
        'fecha' => now()->toDateString(),
        'estado' => 'abierta',
        'saldo_apertura' => 0,
        'abierta_at' => now(),
    ]);
}

/**
 * Crédito del cliente. `registrado_por` importa: CreditoHierarchyService le
 * da al asesor visibility sobre los créditos que él mismo registró, así que un
 * crédito sin ese campo queda invisible para el asesor (aunque el cobro sí
 * sea suyo).
 */
function créditoDe(Cliente $cliente, string $tipo = 'prendario', ?User $registradoPor = null): Credito
{
    $bien = Bien::factory()->paraCliente($cliente)->create();

    return Credito::factory()->paraBien($bien)->create([
        'tipo_credito' => $tipo,
        'agencia_id' => $cliente->agencia_id,
        'empresa_id' => $cliente->empresa_id,
        'registrado_por' => $registradoPor?->id,
    ]);
}

/**
 * Cliente en una agencia concreta. OJO: no se usa `asignadoA($asesor)` porque
 * ese state de ClienteFactory pisa agencia_id/empresa_id con los del ASESOR —
 * y en estos tests el asesor pertenece siempre a la primera agencia, lo que
 * arrastraría al cliente de la otra agencia y haría que el filtro por agencia
 * no se pueda probar.
 */
function clienteEn(Agencia $agencia): Cliente
{
    return Cliente::factory()->forAgencia($agencia)->create([
        'empresa_id' => $agencia->empresa_id,
        'agencia_id' => $agencia->id,
    ]);
}

function cobroHoy(array $attrs, Credito $credito, Cliente $cliente, User $registradoPor, string $monto = '100.00'): Cobro
{
    return Cobro::factory()->create([
        'empresa_id' => $credito->empresa_id,
        'credito_id' => $credito->id,
        'cliente_id' => $cliente->id,
        'registrado_por' => $registradoPor->id,
        'estado' => 'registrado',
        'monto_pagado' => $monto,
        'created_at' => now(),
        ...$attrs,
    ]);
}

/**
 * Venta de contado ya pagada. Se arma con el modelo (y no con VentaFactory)
 * porque el factory genera su propio Bien::factory() colgado del artículo,
 * que a su vez crea cliente/agencia/empresa sueltos y termina chocando con
 * los NOT NULL de la venta.
 */
function ventaDe(Cliente $cliente, User $vendidoPor, Agencia $agencia): Venta
{
    $articulo = Bien::factory()->paraCliente($cliente)->create(['estado' => 'disponible_venta']);

    return Venta::query()->create([
        'empresa_id' => $cliente->empresa_id,
        'agencia_id' => $agencia->id,
        'articulo_type' => $articulo->getMorphClass(),
        'articulo_id' => $articulo->id,
        'cliente_id' => $cliente->id,
        'vendido_por' => $vendidoPor->id,
        'forma_venta' => 'contado',
        'estado' => 'pagada',
        'precio_venta' => 500,
        'inicial' => 500,
        'saldo_pendiente' => 0,
        'pagada_at' => now(),
    ]);
}

function pagoVentaHoy(Venta $venta, User $registradoPor, string $monto, bool $anulada = false): PagoVenta
{
    return PagoVenta::query()->create([
        'venta_id' => $venta->id,
        'empresa_id' => $venta->empresa_id,
        'caja_ciclo_id' => test()->ciclo->id,
        'registrado_por' => $registradoPor->id,
        'tipo' => 'contado',
        'monto' => $monto,
        'medio' => 'efectivo',
        'anulado_at' => $anulada ? now() : null,
        'created_at' => now(),
    ]);
}

/**
 * Movimiento de caja colgado del ciclo ya abierto en beforeEach. Se crea con
 * el modelo y no con el factory a propósito: el definition() de
 * CajaMovimientoFactory arma su propio CajaCiclo::factory(), que termina en un
 * Caja::factory() sin empresa y revienta el NOT NULL, aunque después se pase el
 * ciclo correcto por el state.
 */
function movimientoHoy(User $registradoPor, string $tipo, string $monto, ?Credito $credito = null): CajaMovimiento
{
    return CajaMovimiento::query()->create([
        'caja_ciclo_id' => test()->ciclo->id,
        'empresa_id' => test()->ciclo->empresa_id,
        'tipo' => $tipo,
        'concepto_id' => null,
        'credito_id' => $credito?->id,
        'registrado_por' => $registradoPor->id,
        'monto' => $monto,
        'fecha_caja' => test()->ciclo->fecha,
        'created_at' => now(),
    ]);
}

function desembolsoHoy(Credito $credito, User $registradoPor, string $monto = '500.00'): CajaMovimiento
{
    return movimientoHoy($registradoPor, 'egreso', $monto, $credito);
}

/** Egreso de caja que NO es desembolso (gasto con concepto del catálogo). */
function egresoManualHoy(User $user, string $monto = '777.00'): CajaMovimiento
{
    return movimientoHoy($user, 'egreso', $monto);
}

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

it('does not count a manual egreso as a desembolso', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    desembolsoHoy($credito, $this->asesor, '300.00');

    egresoManualHoy($this->asesor, '777.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(300.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.desembolso.total'));
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
        ->and($data['desembolso']['cantidad'])->toBe(0);
});

it('reports a negative neto when desembolsos exceed cobranzas', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');
    desembolsoHoy($credito, $this->asesor, '900.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(-800.00, $this->getJson('/api/dashboard/resumen')->assertSuccessful()->json('data.neto'));
});
