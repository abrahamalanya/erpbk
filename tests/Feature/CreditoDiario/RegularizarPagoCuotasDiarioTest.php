<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Models\RegularizacionCredito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('public');
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_cuotas' => 45,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');

    $this->adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->adminGeneral->assignRole('administrador_general');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    // Diario de 5 cuotas, desembolsado — las 2 primeras ya vencidas (mora).
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
        'numero_cuotas' => 5,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    subirEvidenciaDesembolsoDiario($this, $creditoId);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    CuotaCredito::where('credito_id', $creditoId)->whereIn('numero_cuota', [1, 2])
        ->update(['fecha_vencimiento' => now()->subDays(5)]);

    $this->credito = Credito::find($creditoId);
});

it('lets administrador_general regularizar varias cuotas atrasadas de un diario sin registrar nada en caja', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 2])
        ->assertSuccessful()->json('data');

    $movimientosAntes = CajaMovimiento::query()->count();

    $response = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/regularizar", [
        'numero_cuotas' => 2,
        'motivo' => 'cliente en situación difícil, se condona la mora para regularizar',
    ])->assertSuccessful();

    expect($response->json('data.estado'))->toBe('activo');

    $pagadas = CuotaCredito::where('credito_id', $this->credito->id)->pagadas()->orderBy('numero_cuota')->pluck('numero_cuota')->all();
    expect($pagadas)->toBe([1, 2]);

    expect(Cobro::where('credito_id', $this->credito->id)->count())->toBe(0)
        ->and(CajaMovimiento::query()->count())->toBe($movimientosAntes);

    $regularizacion = RegularizacionCredito::where('credito_id', $this->credito->id)->firstOrFail();
    expect($regularizacion->numero_cuotas)->toBe(2)
        ->and((string) $regularizacion->monto_condonado)->toBe((string) $preview['total'])
        ->and($regularizacion->motivo)->toBe('cliente en situación difícil, se condona la mora para regularizar')
        ->and($regularizacion->actor_id)->toBe($this->adminGeneral->id)
        ->and($regularizacion->agencia_id)->toBe($this->agencia->id);
});

it('regularizar también corrige el monto_total de las cuotas ya pagadas, sin tocar lo que el cliente ya abonó', function () {
    // Simula una cuota pagada ANTES de corregir el redondeo (como quedaron
    // las cuotas viejas de créditos reales): su monto_total/interés quedó
    // "inflado" (103.00/3.00) en vez del valor correcto (102.50/2.50) que
    // produce capital(100) + interés nominal(2.50) para este crédito.
    $cuota1 = CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->firstOrFail();
    $cuota1->update(['monto_total' => '103.00', 'monto_interes' => '3.00', 'monto_abonado' => '103.00', 'pagada_at' => now()]);

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/regularizar", [
        'numero_cuotas' => 4,
        'motivo' => 'regulariza el resto y corrige el cronograma completo',
    ])->assertSuccessful();

    $cuotas = CuotaCredito::where('credito_id', $this->credito->id)->orderBy('numero_cuota')->get();

    // La cuota 1 (ya pagada) queda con el total correcto (102.50) aunque lo
    // realmente abonado (103.00) se quede como estaba — nunca se le
    // reclama ni se le devuelve la diferencia por el redondeo viejo.
    expect((string) $cuotas[0]->monto_total)->toBe('102.50')
        ->and((string) $cuotas[0]->monto_interes)->toBe('2.50')
        ->and((string) $cuotas[0]->monto_abonado)->toBe('103.00')
        ->and($cuotas[0]->pagada_at)->not->toBeNull();

    foreach ($cuotas as $cuota) {
        expect((string) $cuota->monto_total)->toBe('102.50');
    }

    $sumaTotal = $cuotas->sum(fn (CuotaCredito $c): float => (float) $c->monto_total);
    expect(number_format($sumaTotal, 2, '.', ''))->toBe('512.50');
});

it('editar la tasa de interés también corrige el monto_total de las cuotas ya pagadas, sin tocar lo que el cliente ya abonó', function () {
    // Cuota 1 ya pagada ANTES de este cambio de tasa, con el total que le
    // correspondía a la tasa vieja (15%): capital(100) + interés(2.50) = 102.50.
    $cuota1 = CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->firstOrFail();
    $cuota1->update(['pagada_at' => now(), 'monto_abonado' => '102.50']);
    expect((string) $cuota1->monto_total)->toBe('102.50');

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/actualizar-interes", ['interes' => 30])
        ->assertSuccessful();

    // Con la tasa nueva (30%): interés nominal = 500*30%*1día/3000 = 5.00,
    // total por cuota = 100 + 5 = 105.00 — incluida la cuota 1, que ya
    // estaba pagada: su monto_total se corrige a la tasa nueva aunque lo
    // realmente abonado (102.50, a la tasa vieja) se quede como estaba.
    $cuotas = CuotaCredito::where('credito_id', $this->credito->id)->orderBy('numero_cuota')->get();

    expect((string) $cuotas[0]->monto_total)->toBe('105.00')
        ->and((string) $cuotas[0]->monto_interes)->toBe('5.00')
        ->and((string) $cuotas[0]->monto_abonado)->toBe('102.50')
        ->and($cuotas[0]->pagada_at)->not->toBeNull();

    foreach ($cuotas as $cuota) {
        expect((string) $cuota->monto_total)->toBe('105.00');
    }
});

it('denies an asesor from regularizar cuotas', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/regularizar", [
        'numero_cuotas' => 2,
        'motivo' => 'intento de asesor',
    ])->assertStatus(403);
});

it('denies regularizar a credit that is not diario, even for administrador_general', function () {
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'prendario',
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_cuotas' => 12,
    ]);

    $bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 5000]);

    Sanctum::actingAs($this->asesor, ['*']);
    $prendarioId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 1200, 'tipo_cuota' => 'mensual', 'numero_cuotas' => 4,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$prendarioId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    foreach (Credito::find($prendarioId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$prendarioId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }
    $this->postJson("/api/creditos-prendarios/{$prendarioId}/desembolsar")->assertSuccessful();

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$prendarioId}/regularizar", [
        'numero_cuotas' => 1,
        'motivo' => 'no debería permitirse',
    ])->assertStatus(422);
});

it('requires a motivo to regularizar', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/regularizar", [
        'numero_cuotas' => 1,
    ])->assertStatus(422);
});

it('liquidates the diario when regularizar covers every pending cuota', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);

    $response = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/regularizar", [
        'numero_cuotas' => 5,
        'motivo' => 'regulariza y liquida todo el crédito',
    ])->assertSuccessful();

    expect($response->json('data.estado'))->toBe('liquidado');
    expect(CuotaCredito::where('credito_id', $this->credito->id)->pagadas()->count())->toBe(5);
});

it('scopes the regularizaciones report to administrador_agencia within their own agencia', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/regularizar", [
        'numero_cuotas' => 1,
        'motivo' => 'regularización visible para la propia agencia',
    ])->assertSuccessful();

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroAdminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $otroAdminGeneral->assignRole('administrador_general');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $response = $this->getJson('/api/regularizaciones-credito')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.credito_id'))->toBe($this->credito->id);
});

it('regenera las cuotas pendientes (incluida una con abono parcial) al editar la tasa de un diario ya desembolsado', function () {
    // Abono parcial a la cuota 1 — queda pendiente (pagada_at null) pero con monto_abonado > 0.
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'monto_pagado' => '20.00', 'medio' => 'efectivo',
    ])->assertCreated();

    // Cuota 1 ya está en mora (venció hace 5 días, ver beforeEach): su monto_total
    // es 102.50 (capital 100 + interés 2.50 al 15%, exacto a la décima — ver
    // filasCronograma()), así que la mora sobre ese total (1%/día × 5 días) es
    // 5.12; el pago de 20 cubre esa mora primero y el resto (14.88) es abono.
    $cuota1Antes = CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->firstOrFail();
    $cuota2Antes = CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 2)->firstOrFail();
    expect((string) $cuota1Antes->monto_abonado)->toBe('14.88')
        ->and((string) $cuota1Antes->mora_pagada)->toBe('5.12')
        ->and($cuota1Antes->pagada_at)->toBeNull();

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/actualizar-interes", ['interes' => 30])
        ->assertSuccessful();

    // Mismo cálculo que filasCronograma(): capital de cada cuota sin tocar, interés nuevo sobre monto_prestamo.
    $interesNominal = bcdiv(bcmul(bcmul('500', '30', 10), '1', 10), '3000', 2);
    $totalEsperado = bcadd((string) $cuota1Antes->monto_capital, $interesNominal, 0);

    $cuota1Despues = $cuota1Antes->fresh();
    $cuota2Despues = $cuota2Antes->fresh();

    expect((string) $cuota1Despues->monto_total)->toBe($totalEsperado.'.00')
        ->and((string) $cuota1Despues->monto_abonado)->toBe('14.88')
        ->and((string) $cuota1Despues->mora_pagada)->toBe('5.12')
        ->and($cuota1Despues->pagada_at)->toBeNull()
        ->and((string) $cuota2Despues->monto_total)->toBe($totalEsperado.'.00')
        ->and((string) $cuota1Despues->monto_capital)->toBe((string) $cuota1Antes->monto_capital);
});
