<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\ConfiguracionVenta;
use App\Modules\Venta\Models\Venta;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionVenta::factory()->deEmpresa($this->empresa)->create(['interes_mensual_default' => 5]);

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');

    $caja = Caja::factory()->create(['user_id' => $this->admin->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);
});

it('registers a crédito sale with a cronograma using the configured default interest rate', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 1000]);

    $response = $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'credito',
        'inicial' => 200,
        'numero_cuotas' => 4,
        'tipo_cuota' => 'mensual',
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('activa')
        ->and($response->json('data.interes'))->toBe('5.00')
        ->and($response->json('data.saldo_pendiente'))->toBe('800.00')
        ->and($response->json('data.cuotas'))->toHaveCount(4);

    // Cada cuota: capital 200, interés 800*5% = 40, total 240.
    expect($response->json('data.cuotas.0.monto_capital'))->toBe('200.00')
        ->and($response->json('data.cuotas.0.monto_interes'))->toBe('40.00')
        ->and($response->json('data.cuotas.0.monto_total'))->toBe('240.00');

    expect($bien->fresh()->estado)->toBe('reservada');

    $venta = Venta::findOrFail($response->json('data.id'));
    expect($venta->documentos->pluck('tipo')->all())->toBe(['contrato_credito']);
});

it('allows a 0 interest rate override (venta a crédito sin interés)', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 500]);

    $response = $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'credito',
        'inicial' => 100,
        'numero_cuotas' => 2,
        'tipo_cuota' => 'mensual',
        'interes' => 0,
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.interes'))->toBe('0.00')
        ->and($response->json('data.cuotas.0.monto_interes'))->toBe('0.00')
        ->and($response->json('data.cuotas.0.monto_total'))->toBe('200.00');
});

it('spaces cuotas by tipo_cuota and prorates the interest to the period length', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 1000]);

    $response = $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'credito',
        'inicial' => 200,
        'numero_cuotas' => 4,
        'tipo_cuota' => 'semanal',
        'medio' => 'efectivo',
    ])->assertCreated();

    $hoy = now()->startOfDay();

    expect($response->json('data.tipo_cuota'))->toBe('semanal')
        ->and(substr((string) $response->json('data.cuotas.0.fecha_vencimiento'), 0, 10))->toBe($hoy->copy()->addDays(7)->toDateString())
        ->and(substr((string) $response->json('data.cuotas.1.fecha_vencimiento'), 0, 10))->toBe($hoy->copy()->addDays(14)->toDateString())
        // Interés prorrateado a 7 días en vez de 30: 800 * 5% * 7/30 = 9.33.
        ->and($response->json('data.cuotas.0.monto_interes'))->toBe('9.33');
});

it('rejects a venta a crédito without a tipo_cuota', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 500]);

    $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'credito',
        'inicial' => 100,
        'numero_cuotas' => 2,
        'medio' => 'efectivo',
    ])->assertUnprocessable();
});

it('rejects an inicial greater than or equal to the precio_venta', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 500]);

    $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'credito',
        'inicial' => 500,
        'numero_cuotas' => 2,
        'tipo_cuota' => 'mensual',
        'medio' => 'efectivo',
    ])->assertUnprocessable();
});
