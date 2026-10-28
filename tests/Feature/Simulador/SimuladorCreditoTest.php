<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Simulador\Models\SimulacionCredito;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'prendario', 'interes_default' => 10, 'plazo_dias' => 30, 'max_cuotas' => 1,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'vehicular', 'interes_default' => 8, 'plazo_dias' => 90, 'max_cuotas' => 12,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario', 'interes_default' => 20, 'plazo_dias' => 30, 'max_cuotas' => 30,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

it('registra una simulación de crédito con el cronograma calculado, sin usar los defaults cuando el asesor los sobrescribe', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'prendario',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 400,
        'interes' => 15,
        'tipo_cuota' => 'semanal',
    ])->assertCreated();

    expect($response->json('data.cronograma'))->toHaveCount(4)
        ->and($response->json('data.plazo_dias'))->toBe(28)
        ->and((float) $response->json('data.interes'))->toBe(15.0)
        ->and($response->json('data.cliente.id'))->toBe($this->cliente->id);

    expect(SimulacionCredito::count())->toBe(1);
});

it('usa el interés y numero de cuotas por defecto de la configuración cuando no se envían', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'vehicular',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 1000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect((float) $response->json('data.interes'))->toBe(8.0);
});

it('registra una simulación de crédito diario', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'diario',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 300,
        'tipo_cuota' => 'diario',
    ])->assertCreated();

    expect($response->json('data.cronograma'))->toHaveCount(30)
        ->and((float) $response->json('data.interes'))->toBe(20.0);
});

it('simula con tipo de interés compuesto cuando se indica', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'vehicular',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 1000,
        'interes' => 8,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 3,
        'tipo_interes' => 'compuesto',
    ])->assertCreated();

    expect($response->json('data.tipo_interes'))->toBe('compuesto');

    $simple = $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'vehicular',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 1000,
        'interes' => 8,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 3,
    ])->assertCreated();

    expect($simple->json('data.tipo_interes'))->toBe('simple')
        ->and($response->json('data.monto_total_pagar'))->not->toBe($simple->json('data.monto_total_pagar'));
});

it('rejects an invalid tipo_interes', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'prendario',
        'monto_prestamo' => 400,
        'tipo_cuota' => 'mensual',
        'tipo_interes' => 'otro',
    ])->assertUnprocessable()->assertJsonValidationErrors('tipo_interes');
});

it('rejects a numero_cuotas above the configured max_cuotas for the tipo_credito', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'vehicular',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 1000,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 24,
    ])->assertUnprocessable()->assertJsonValidationErrors('numero_cuotas');
});

it('rejects a cliente that belongs to a different agencia within the same empresa', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $clienteDeOtraAgencia = Cliente::factory()->forAgencia($otraAgencia)->create();

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'prendario',
        'cliente_id' => $clienteDeOtraAgencia->id,
        'monto_prestamo' => 400,
        'tipo_cuota' => 'mensual',
    ])->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
});

it('lists only simulaciones visible to the actor and lets its author delete it', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    $propia = SimulacionCredito::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id, 'registrado_por' => $this->asesor->id,
    ]);
    SimulacionCredito::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id, 'registrado_por' => $otroAsesor->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/simulaciones-credito')->assertSuccessful()->assertJsonCount(1, 'data.data');

    $this->deleteJson("/api/simulaciones-credito/{$propia->id}")->assertSuccessful();
    expect(SimulacionCredito::count())->toBe(1);
});

it('prevents an asesor from deleting a simulación registered by another asesor', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    $ajena = SimulacionCredito::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id, 'registrado_por' => $otroAsesor->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->deleteJson("/api/simulaciones-credito/{$ajena->id}")->assertForbidden();
});

it('calcula sin cliente ni agencia usando los defaults de empresa y sin persistir', function () {
    $this->asesor->update(['agencia_id' => null]);
    Sanctum::actingAs($this->asesor->fresh(), ['*']);

    $respuesta = $this->postJson('/api/simulaciones-credito/calcular', [
        'tipo_credito' => 'vehicular', 'monto_prestamo' => '1000.00',
        'tipo_cuota' => 'mensual', 'numero_cuotas' => 3,
    ])->assertSuccessful()->assertJsonPath('data.interes', '8.00');

    // 1000/3 ≈ 333.33 de capital + 80.00 de interés (1000×8%×30/30) por cuota;
    // las cuotas 1-2 redondean a la décima (413.33 -> 413.30), la 3.ª
    // (última) queda exacta para cerrar el total real sin sobrante/faltante
    // -> 413.40. Total: 413.30+413.30+413.40 = 1240.00.
    expect($respuesta->json('data.cronograma'))->toHaveCount(3)
        ->and($respuesta->json('data.cronograma.2.saldo_pendiente'))->toBe('0.00')
        ->and($respuesta->json('data.monto_total_pagar'))->toBe('1240.00')
        ->and(SimulacionCredito::count())->toBe(0);
    $this->assertDatabaseCount('creditos_prendarios', 0);
    $this->assertDatabaseCount('cuotas_credito_prendario', 0);
});

it('guarda sin cliente ni agencia y mantiene el acceso al historial', function () {
    $this->asesor->update(['agencia_id' => null]);
    Sanctum::actingAs($this->asesor->fresh(), ['*']);
    $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'vehicular', 'monto_prestamo' => '1000',
        'tipo_cuota' => 'mensual', 'numero_cuotas' => 3,
    ])->assertCreated()->assertJsonPath('data.cliente_id', null)->assertJsonPath('data.agencia_id', null);
    $this->getJson('/api/simulaciones-credito')->assertSuccessful()->assertJsonCount(1, 'data.data');
});

it('requiere tasa explícita cuando no existe configuración', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $datos = ['tipo_credito' => 'hipotecario', 'monto_prestamo' => '1000', 'tipo_cuota' => 'mensual', 'numero_cuotas' => 2];
    $this->postJson('/api/simulaciones-credito/calcular', $datos)->assertUnprocessable()->assertJsonValidationErrors('interes');
    $this->postJson('/api/simulaciones-credito/calcular', [...$datos, 'interes' => '10'])->assertSuccessful();
});

it('valida cuotas y rechaza resultados inválidos antes de persistir', function (array $extra, string $campo) {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/simulaciones-credito/calcular', [
        'tipo_credito' => 'vehicular', 'monto_prestamo' => '1000', 'tipo_cuota' => 'mensual', ...$extra,
    ])->assertUnprocessable()->assertJsonValidationErrors($campo);
    expect(SimulacionCredito::count())->toBe(0);
})->with([
    'máximo configurado' => [['numero_cuotas' => 13], 'numero_cuotas'],
    'cuotas fraccionarias' => [['numero_cuotas' => 1.5], 'numero_cuotas'],
    'interés negativo por redondeo' => [['monto_prestamo' => '0.08', 'interes' => '0', 'numero_cuotas' => 2], 'numero_cuotas'],
]);

it('protege cálculo y defaults con los permisos existentes', function () {
    $this->asesor->syncRoles([]);
    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/simulaciones-credito/configuracion')->assertForbidden();
    $this->postJson('/api/simulaciones-credito/calcular', [
        'tipo_credito' => 'vehicular', 'monto_prestamo' => '1000', 'tipo_cuota' => 'mensual',
    ])->assertForbidden();
});

it('devuelve las tasas y límites por tipo desde el módulo del simulador', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/simulaciones-credito/configuracion')->assertSuccessful()
        ->assertJsonPath('data.vehicular.interes_default', '8.00')
        ->assertJsonPath('data.vehicular.max_cuotas', 12);
});

it('rechaza identificadores de cliente malformados sin errores internos', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'vehicular', 'monto_prestamo' => '1000', 'tipo_cuota' => 'mensual',
        'cliente_id' => [$this->cliente->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
});

it('calcula cuotas de importe fijo y ajusta la última para cancelar el capital', function (string $tipoInteres, string $total, string $ultima) {
    Sanctum::actingAs($this->asesor, ['*']);
    $datos = [
        'tipo_credito' => 'vehicular', 'monto_prestamo' => '1000.00', 'interes' => '8',
        'tipo_cuota' => 'mensual', 'tipo_interes' => $tipoInteres,
        'modo_calculo' => 'importe_cuota', 'importe_cuota' => '300.00',
    ];
    $response = $this->postJson('/api/simulaciones-credito/calcular', $datos)->assertSuccessful()
        ->assertJsonPath('data.numero_cuotas_calculadas', 5)
        ->assertJsonPath('data.saldo_residual', '0.00')
        ->assertJsonPath('data.monto_total_pagar', $total)
        ->assertJsonPath('data.cronograma.4.monto_total', $ultima);
    foreach (array_slice($response->json('data.cronograma'), 0, 4) as $cuota) {
        expect($cuota['monto_total'])->toBe('300.00');
    }
    expect(SimulacionCredito::count())->toBe(0);
    $this->postJson('/api/simulaciones-credito', $datos)->assertCreated()
        ->assertJsonPath('data.modo_calculo', 'importe_cuota')
        ->assertJsonPath('data.importe_cuota', '300.00')
        ->assertJsonPath('data.numero_cuotas', 5)
        ->assertJsonPath('data.cronograma', $response->json('data.cronograma'));
    $this->assertDatabaseCount('creditos_prendarios', 0);
})->with([
    'simple' => ['simple', '1400.00', '200.00'],
    'francés' => ['compuesto', '1209.34', '9.34'],
]);

it('ajusta únicamente la última cuota francesa del simulador conservando el motor de créditos reales', function (int $n, string $ultima) {
    Sanctum::actingAs($this->asesor, ['*']);
    $motor = app(CreditoService::class);
    $original = $motor->previsualizarCronograma('1000', '8', 'mensual', $n, 'compuesto', 'vehicular');
    $resultado = $this->postJson('/api/simulaciones-credito/calcular', [
        'tipo_credito' => 'vehicular', 'monto_prestamo' => '1000', 'interes' => '8',
        'tipo_cuota' => 'mensual', 'tipo_interes' => 'compuesto', 'numero_cuotas' => $n,
    ])->assertSuccessful()->assertJsonPath('data.saldo_residual', '0.00');
    expect($resultado->json('data.cronograma.'.($n - 1).'.monto_total'))->toBe($ultima);
    foreach (array_slice($original['cuotas'], 0, $n - 1) as $i => $cuota) {
        foreach ($cuota as $key => $valor) {
            expect($resultado->json("data.cronograma.{$i}.{$key}"))->toBe($valor);
        }
    }
    expect($motor->previsualizarCronograma('1000', '8', 'mensual', $n, 'compuesto', 'vehicular'))->toBe($original);
})->with(['residual positivo' => [3, '388.10'], 'residual negativo' => [2, '560.73']]);

it('rechaza cuotas insuficientes, plazos excesivos y modalidades ambiguas', function (array $extra, string $campo) {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/simulaciones-credito/calcular', [
        'tipo_credito' => 'vehicular', 'monto_prestamo' => '1000', 'interes' => '8',
        'tipo_cuota' => 'mensual', 'modo_calculo' => 'importe_cuota', 'importe_cuota' => '300', ...$extra,
    ])->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    'menor que interés' => [['importe_cuota' => '79.99'], 'importe_cuota'],
    'igual a interés francés' => [['importe_cuota' => '80', 'tipo_interes' => 'compuesto'], 'importe_cuota'],
    'más cuotas que máximo' => [['importe_cuota' => '81'], 'importe_cuota'],
    'sin importe' => [['importe_cuota' => null], 'importe_cuota'],
    'dos modalidades' => [['numero_cuotas' => 3], 'numero_cuotas'],
    'decimales excesivos' => [['importe_cuota' => '300.001'], 'importe_cuota'],
]);

it('admite tasa cero y fechas mensuales sin desborde en el simulador francés', function () {
    $this->travelTo(Carbon::parse('2026-01-31 10:00:00'));
    Sanctum::actingAs($this->asesor, ['*']);
    $datos = ['tipo_credito' => 'vehicular', 'monto_prestamo' => '1000', 'interes' => '0', 'tipo_cuota' => 'mensual', 'tipo_interes' => 'compuesto'];
    $this->postJson('/api/simulaciones-credito/calcular', [...$datos, 'numero_cuotas' => 3])
        ->assertSuccessful()->assertJsonPath('data.cronograma.2.monto_total', '333.40')
        ->assertJsonPath('data.saldo_residual', '0.00')->assertJsonPath('data.total_interes', '0.00');
    $this->postJson('/api/simulaciones-credito/calcular', [...$datos, 'modo_calculo' => 'importe_cuota', 'importe_cuota' => '333.33'])
        ->assertSuccessful()->assertJsonPath('data.numero_cuotas_calculadas', 4)
        ->assertJsonPath('data.cronograma.0.fecha_vencimiento', '2026-02-28')
        ->assertJsonPath('data.cronograma.1.fecha_vencimiento', '2026-03-31')
        ->assertJsonPath('data.cronograma.3.monto_total', '0.01')->assertJsonPath('data.saldo_residual', '0.00');
});

it('conserva las frecuencias y amortiza todo el capital por importe en los cuatro tipos', function (string $tipo, string $frecuencia, string $tipoInteres) {
    ConfiguracionCredito::query()->where('empresa_id', $this->empresa->id)->update(['max_cuotas' => 12]);
    $this->travelTo(Carbon::parse('2026-01-31 10:00:00'));
    Sanctum::actingAs($this->asesor, ['*']);
    $respuesta = $this->postJson('/api/simulaciones-credito/calcular', [
        'tipo_credito' => $tipo, 'tipo_cuota' => $frecuencia, 'tipo_interes' => $tipoInteres,
        'monto_prestamo' => '1000', 'interes' => '8', 'modo_calculo' => 'importe_cuota', 'importe_cuota' => '300',
    ])->assertSuccessful()->assertJsonPath('data.saldo_residual', '0.00');
    $cuotas = $respuesta->json('data.cronograma');
    $capital = '0.00';
    foreach ($cuotas as $i => $cuota) {
        $capital = bcadd($capital, $cuota['monto_capital'], 2);
        expect(bcadd($cuota['monto_capital'], $cuota['monto_interes'], 2))->toBe($cuota['monto_total'])
            ->and(bccomp($cuota['saldo_pendiente'], '0', 2))->toBeGreaterThanOrEqual(0);
        if ($i < count($cuotas) - 1) {
            expect($cuota['monto_total'])->toBe('300.00');
        }
    }
    expect($capital)->toBe('1000.00');
    if ($tipo === 'diario' && $tipoInteres === 'simple') {
        expect($cuotas[3]['fecha_vencimiento'])->toBe('2026-03-02');
    }
})->with([
    ['prendario', 'quincenal', 'simple'], ['prendario', 'quincenal', 'compuesto'],
    ['vehicular', 'diario', 'simple'], ['vehicular', 'diario', 'compuesto'],
    ['hipotecario', 'mensual', 'simple'], ['hipotecario', 'mensual', 'compuesto'],
    ['diario', 'semanal', 'simple'], ['diario', 'semanal', 'compuesto'],
]);
