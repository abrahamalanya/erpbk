<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\PagoVenta;
use App\Modules\Venta\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Sube la evidencia obligatoria (video + GPS) de un crédito diario, requisito
 * previo a desembolsar() desde CreditoService — usado por todas las suites
 * que desembolsan un crédito diario como parte de su setup. El actor
 * autenticado al momento de llamar esta función debe ser el mismo que luego
 * va a desembolsar (misma autorización: Gate 'desembolsar').
 */
function subirEvidenciaDesembolsoDiario(TestCase $test, int $creditoId): void
{
    $test->postJson("/api/creditos-prendarios/{$creditoId}/evidencia-desembolso", [
        'video' => UploadedFile::fake()->create('evidencia.mp4', 1024, 'video/mp4'),
        'latitud' => -8.379,
        'longitud' => -74.553,
        'precision_metros' => 8.5,
    ])->assertSuccessful();
}

/*
|--------------------------------------------------------------------------
| Montaje de Cobranza/Caja/Ventas (Dashboard)
|--------------------------------------------------------------------------
|
| Compartido por las suites de App\Modules\Dashboard: el resumen del día
| (DashboardInicialTest) y el gráfico agrupado Cobranza vs. Desembolso
| (DashboardGraficoCobranzaDesembolsoTest). Viven acá (y no en un archivo
| propio con require) porque Pest.php ya es el lugar establecido en este
| proyecto para helpers de test compartidos (ver subirEvidenciaDesembolsoDiario
| arriba).
|
*/

/**
 * El JSON de un 100.0 viaja como "100" y el de 400.50 como "400.5", así que
 * assertJsonPath con un float literal es frágil según el monto. Compara
 * siempre como número, sin importar si del JSON vuelve int o float.
 */
function assertTotal(float $esperado, mixed $real): void
{
    expect(round((float) $real, 2))->toBe(round($esperado, 2));
}

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
 * Movimiento de caja colgado del ciclo ya abierto en beforeEach (test()->ciclo).
 * Se crea con el modelo y no con el factory a propósito: el definition() de
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

/**
 * Crédito en un estado de cartera (activo/vencido) con una sola cuota de
 * saldo conocido (monto_total - monto_abonado), para probar los totales de
 * cartera del dashboard (Vencidos, Cartera activa por tipo) sin depender del
 * cronograma real. El filtro asesor_id de esos totales mira cliente.asesor_id
 * (igual que CreditoController::index()), no credito.registrado_por —
 * asignar el asesor al $cliente *antes* de llamar a esta función, con
 * `Cliente::factory()->asignadoA($asesor)`.
 */
function creditoCarteraDe(Cliente $cliente, string $estado, string $montoTotal, string $montoAbonado, string $tipo = 'prendario'): Credito
{
    $bien = Bien::factory()->paraCliente($cliente)->create();

    $credito = Credito::factory()->paraBien($bien)->create(['estado' => $estado, 'tipo_credito' => $tipo]);

    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 1,
        'fecha_vencimiento' => now()->subDays(5)->toDateString(),
        'monto_total' => $montoTotal,
        'monto_abonado' => $montoAbonado,
    ]);

    return $credito;
}

/** Atajo de creditoCarteraDe() para créditos vencidos — el caso ya cubierto antes de que existiera "Cartera activa por tipo". */
function creditoVencidoDe(Cliente $cliente, string $montoTotal, string $montoAbonado, string $tipo = 'prendario'): Credito
{
    return creditoCarteraDe($cliente, 'vencido', $montoTotal, $montoAbonado, $tipo);
}

/**
 * Busca, dentro de una lista de filas con clave `tipo_credito` (el desglose
 * por tipo que devuelven DashboardCarteraActivaPorTipoTest y
 * DashboardProduccionPorAsesorTest), la fila de un tipo concreto.
 */
function filaTipo(array $filas, string $tipo): array
{
    foreach ($filas as $fila) {
        if ($fila['tipo_credito'] === $tipo) {
            return $fila;
        }
    }

    throw new RuntimeException("No hay fila para el tipo {$tipo}");
}
