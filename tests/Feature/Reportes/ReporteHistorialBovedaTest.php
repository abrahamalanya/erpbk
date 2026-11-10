<?php

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\BovedaMovimiento;
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

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->conceptoIngreso = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'ingreso']);
    $this->conceptoGasto = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto']);
});

/**
 * Apertura la caja del asesor, agrega un ingreso/egreso manual, un cobro
 * (abono), un billetaje aprobado y un desembolso de crédito, y cierra con un
 * faltante conocido. El billetaje y el cierre generan automáticamente los
 * movimientos de la bóveda de agencia que financia al asesor (egreso de
 * billetaje entregado + ingreso de entrega por cierre), así que esta misma
 * preparación deja lista también la fila de bóveda a verificar.
 *
 * @return array{caja_ciclo: CajaCiclo, boveda: Boveda}
 */
function prepararHistorialBovedaCiclo(User $asesor, Concepto $conceptoIngreso, Concepto $conceptoGasto): array
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

    $boveda = Boveda::where('agencia_id', $asesor->agencia_id)->firstOrFail();
    $billetaje = Billetaje::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'boveda_id' => $boveda->id,
        'empresa_id' => $asesor->empresa_id,
        'monto' => 80,
        'estado' => 'aprobado',
        'motivo' => 'Vuelto insuficiente',
        'asesor_id' => $asesor->id,
        'solicitado_por' => $asesor->id,
        'fecha_resolucion' => now(),
    ]);

    CajaMovimiento::query()->create([
        'caja_ciclo_id' => $ciclo->id,
        'empresa_id' => $ciclo->empresa_id,
        'tipo' => 'billetaje',
        'monto' => 80,
        'billetaje_id' => $billetaje->id,
        'registrado_por' => $asesor->id,
        'fecha_caja' => $ciclo->fecha,
    ]);

    $bovedaCiclo = $boveda->cicloAbierto()->firstOrFail();

    BovedaMovimiento::query()->create([
        'boveda_ciclo_id' => $bovedaCiclo->id,
        'empresa_id' => $bovedaCiclo->empresa_id,
        'tipo' => 'egreso',
        'monto' => 80,
        'concepto' => 'Billetaje entregado',
        'billetaje_id' => $billetaje->id,
        'caja_ciclo_id' => $ciclo->id,
        'registrado_por' => $asesor->id,
        'fecha_boveda' => $bovedaCiclo->fecha,
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

    return ['caja_ciclo' => $ciclo->fresh(), 'boveda' => $boveda->fresh()];
}

it('denies a role without historial_boveda.ver', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->getJson('/api/reportes/historial-boveda?fecha='.now()->toDateString())->assertForbidden();
});

it('lists a merged row for the caja ciclo and its funding boveda ciclo with the spec totals', function () {
    $preparado = prepararHistorialBovedaCiclo($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    Sanctum::actingAs($this->admin, ['*']);
    $response = $this->getJson('/api/reportes/historial-boveda?fecha='.now()->toDateString())->assertSuccessful();

    $filas = collect($response->json('data'));
    // La bóveda de agencia que financia al asesor, más la bóveda principal
    // que se abre en cascada junto con ella (ver BovedaService::asegurarAbierta()),
    // más la caja del asesor.
    expect($filas)->toHaveCount(3);

    $filaCaja = $filas->firstWhere('fila_tipo', 'caja');
    expect($filaCaja['estado'])->toBe('cerrada')
        ->and($filaCaja['usuario']['id'])->toBe($this->asesor->id)
        ->and($filaCaja['saldo_inicial'])->toBe('0.00')
        ->and($filaCaja['billetaje'])->toBe('80.00')
        ->and($filaCaja['abonos'])->toBe('200.00')
        ->and($filaCaja['ingresos'])->toBe('100.00')
        ->and($filaCaja['ventas'])->toBe('0.00')
        ->and($filaCaja['egresos'])->toBe('30.00')
        ->and($filaCaja['desembolsos'])->toBe('150.00')
        ->and($filaCaja['total_ingresos'])->toBe('380.00')
        ->and($filaCaja['total_egresos'])->toBe('180.00')
        ->and($filaCaja['faltante'])->toBe('80.00')
        ->and($filaCaja['sobrante'])->toBe('0.00')
        ->and($filaCaja['saldo_cierre'])->toBe('120.00');

    $filaBoveda = $filas->first(fn (array $fila): bool => $fila['fila_tipo'] === 'boveda' && $fila['tipo'] === 'Agencia');
    expect($filaBoveda['estado'])->toBe('abierta')
        ->and($filaBoveda['tipo'])->toBe('Agencia')
        ->and($filaBoveda['billetaje'])->toBe('0.00')
        ->and($filaBoveda['abonos'])->toBe('0.00')
        ->and($filaBoveda['ventas'])->toBe('0.00')
        ->and($filaBoveda['ingresos'])->toBe('120.00')
        ->and($filaBoveda['egresos'])->toBe('0.00')
        ->and($filaBoveda['desembolsos'])->toBe('80.00')
        ->and($filaBoveda['total_ingresos'])->toBe('120.00')
        ->and($filaBoveda['total_egresos'])->toBe('80.00')
        ->and($filaBoveda['faltante'])->toBe('0.00')
        ->and($filaBoveda['sobrante'])->toBe('0.00')
        ->and($filaBoveda['saldo_cierre'])->toBe('40.00');
});

it('filters by estado', function () {
    prepararHistorialBovedaCiclo($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    Sanctum::actingAs($this->admin, ['*']);

    // Las dos bóvedas (principal + agencia) quedan abiertas; solo la caja se cerró.
    $this->getJson('/api/reportes/historial-boveda?fecha='.now()->toDateString().'&estado=abierta')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.fila_tipo', 'boveda')
        ->assertJsonPath('data.1.fila_tipo', 'boveda');

    $this->getJson('/api/reportes/historial-boveda?fecha='.now()->toDateString().'&estado=cerrada')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fila_tipo', 'caja');
});

it('filters by agencia_id', function () {
    prepararHistorialBovedaCiclo($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();

    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/reportes/historial-boveda?fecha='.now()->toDateString().'&agencia_id='.$otraAgencia->id)
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');

    $this->getJson('/api/reportes/historial-boveda?fecha='.now()->toDateString().'&agencia_id='.$this->agencia->id)
        ->assertSuccessful()
        ->assertJsonCount(2, 'data');
});

it('filters out rows from other dates', function () {
    prepararHistorialBovedaCiclo($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);

    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/reportes/historial-boveda?fecha='.now()->subDay()->toDateString())
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

it('downloads the boveda cierre pdf for an authorized actor and denies others', function () {
    $preparado = prepararHistorialBovedaCiclo($this->asesor, $this->conceptoIngreso, $this->conceptoGasto);
    $bovedaCiclo = $preparado['boveda']->cicloAbierto()->firstOrFail();

    Sanctum::actingAs($this->admin, ['*']);
    $this->get("/api/reportes/historial-boveda/boveda/{$bovedaCiclo->id}/reporte/pdf")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->get("/api/reportes/historial-boveda/boveda/{$bovedaCiclo->id}/reporte/pdf")->assertForbidden();
});
