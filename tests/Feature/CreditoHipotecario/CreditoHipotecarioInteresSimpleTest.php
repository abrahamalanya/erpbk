<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoHipotecario\Models\Inmueble;
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

function sumaDecimal($cuotas, string $campo): string
{
    return $cuotas->reduce(fn (string $total, $cuota): string => bcadd($total, (string) $cuota->{$campo}, 2), '0.00');
}

function registrarYDesembolsarHipotecarioSimple($test, int $numeroCuotas, string $monto): int
{
    Sanctum::actingAs($test->asesor, ['*']);

    $creditoId = $test->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$test->inmueble->id],
        'supervisado_por' => $test->adminAgencia->id,
        'monto_prestamo' => $monto,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => $numeroCuotas,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($test->adminAgencia, ['*']);
    $test->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();
    $test->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar", [])->assertSuccessful();

    return $creditoId;
}

it('generates a bullet cronograma for 6000 @ 8% in 6 cuotas: 5 interest-only, last one with full capital', function () {
    $creditoId = registrarYDesembolsarHipotecarioSimple($this, 6, '6000');

    $cuotas = Credito::find($creditoId)->cuotas()->orderBy('numero_cuota')->get();

    expect($cuotas)->toHaveCount(6);

    foreach ($cuotas->take(5) as $cuota) {
        expect((string) $cuota->monto_capital)->toBe('0.00')
            ->and((string) $cuota->monto_interes)->toBe('480.00')
            ->and((string) $cuota->monto_total)->toBe('480.00');
    }

    expect((string) $cuotas[5]->monto_capital)->toBe('6000.00')
        ->and((string) $cuotas[5]->monto_interes)->toBe('480.00')
        ->and((string) $cuotas[5]->monto_total)->toBe('6480.00');

    expect(sumaDecimal($cuotas, 'monto_capital'))->toBe('6000.00')
        ->and(sumaDecimal($cuotas, 'monto_interes'))->toBe('2880.00')
        ->and(sumaDecimal($cuotas, 'monto_total'))->toBe('8880.00');
});

it('charges the full capital plus its interest when the hipotecario crédito has a single cuota', function () {
    $creditoId = registrarYDesembolsarHipotecarioSimple($this, 1, '6000');

    $cuota = Credito::find($creditoId)->cuotas()->sole();

    expect((string) $cuota->monto_capital)->toBe('6000.00')
        ->and((string) $cuota->monto_interes)->toBe('480.00')
        ->and((string) $cuota->monto_total)->toBe('6480.00');
});

it('rounds cuotas with fractional amounts without leaving cent gaps in the totals', function () {
    $creditoId = registrarYDesembolsarHipotecarioSimple($this, 5, '6173.45');

    $cuotas = Credito::find($creditoId)->cuotas()->orderBy('numero_cuota')->get();

    expect(sumaDecimal($cuotas, 'monto_capital'))->toBe('6173.45')
        ->and(sumaDecimal($cuotas, 'monto_total'))->toBe(
            bcadd('6173.45', sumaDecimal($cuotas, 'monto_interes'), 2)
        );
});

it('does not change the compuesto schedule nor other tipos de crédito when generating a hipotecario simple cronograma', function () {
    $service = app(CreditoService::class);

    $compuesto = $service->previsualizarCronograma('6000', '8', 'mensual', 6, 'compuesto', 'hipotecario');
    expect((string) $compuesto['cuotas'][0]['monto_capital'])->not->toBe('0.00');

    $prendario = $service->previsualizarCronograma('6000', '8', 'mensual', 6, 'simple', 'prendario');
    expect((string) $prendario['cuotas'][0]['monto_capital'])->toBe('1000.00');

    $vehicular = $service->previsualizarCronograma('6000', '8', 'mensual', 6, 'simple', 'vehicular');
    expect((string) $vehicular['cuotas'][0]['monto_capital'])->toBe('1000.00');
});

it('produces the same cronograma from the simulador as the real crédito preview for the same parameters', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $motor = app(CreditoService::class);
    $preview = $motor->previsualizarCronograma('6000', '8', 'mensual', 6, 'simple', 'hipotecario');

    $simulado = $this->postJson('/api/simulaciones-credito/calcular', [
        'tipo_credito' => 'hipotecario', 'monto_prestamo' => '6000', 'interes' => '8',
        'tipo_cuota' => 'mensual', 'numero_cuotas' => 6,
    ])->assertSuccessful();

    expect($simulado->json('data.monto_total_pagar'))->toBe('8880.00')
        ->and($simulado->json('data.total_interes'))->toBe('2880.00');

    foreach ($preview['cuotas'] as $i => $cuota) {
        expect($simulado->json("data.cronograma.{$i}.monto_capital"))->toBe($cuota['monto_capital'])
            ->and($simulado->json("data.cronograma.{$i}.monto_interes"))->toBe($cuota['monto_interes'])
            ->and($simulado->json("data.cronograma.{$i}.monto_total"))->toBe($cuota['monto_total']);
    }
});

it('does not alter an already generated schedule or registered pagos when the interés rate is edited later', function () {
    $creditoId = registrarYDesembolsarHipotecarioSimple($this, 6, '6000');
    $credito = Credito::find($creditoId);

    $primeraCuota = $credito->cuotas()->orderBy('numero_cuota')->first();
    $primeraCuota->update(['monto_abonado' => 480, 'pagada_at' => now()]);

    $adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $adminGeneral->assignRole('administrador_general');

    Sanctum::actingAs($adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-interes", ['interes' => '10'])->assertSuccessful();

    $cuotas = $credito->fresh()->cuotas()->orderBy('numero_cuota')->get();

    // El capital de cada cuota (dónde se cobra) no cambia con la tasa nueva.
    expect((string) $cuotas[0]->monto_capital)->toBe('0.00')
        ->and((string) $cuotas[5]->monto_capital)->toBe('6000.00')
        ->and((string) $primeraCuota->fresh()->monto_abonado)->toBe('480.00');

    // El interés nuevo (10% de 6000 = 600) se recalcula en todas las cuotas,
    // pagadas o no — la primera queda "pagada de más" sin que se le reclame.
    expect((string) $cuotas[0]->monto_interes)->toBe('600.00')
        ->and((string) $cuotas[0]->monto_total)->toBe('600.00')
        ->and(sumaDecimal($cuotas, 'monto_capital'))->toBe('6000.00')
        ->and(sumaDecimal($cuotas, 'monto_total'))->toBe('9600.00');
});
