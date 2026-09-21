<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
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

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    // Registra, aprueba, firma y desembolsa un diario de 5 cuotas — chico y
    // manejable para los tests de esta suite.
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

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $this->credito = Credito::find($creditoId);
});

it('pays 1 cuota, marking the oldest pendiente as pagada without creating a sucesor', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 1])
        ->assertSuccessful()->json('data');

    $response = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 1, 'monto_pagado' => $preview['total'], 'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.id'))->toBe($this->credito->id)
        ->and($response->json('data.estado'))->toBe('activo');

    $primera = CuotaCredito::where('credito_id', $this->credito->id)->orderBy('numero_cuota')->first();
    expect($primera->pagada_at)->not->toBeNull()
        ->and((string) $primera->mora_pagada)->toBe('0.00')
        ->and($primera->cobro_id)->not->toBeNull();

    $cobro = Cobro::where('credito_id', $this->credito->id)->firstOrFail();
    expect($cobro->operacion)->toBe('pago_cuotas_diario')
        ->and($cobro->credito_sucesor_id)->toBeNull();
});

it('pays 3 cuotas consecutivas in a single call', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 3])
        ->assertSuccessful()->json('data');
    expect($preview['cuotas'])->toHaveCount(3);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 3, 'monto_pagado' => $preview['total'], 'medio' => 'efectivo',
    ])->assertCreated();

    $pagadas = CuotaCredito::where('credito_id', $this->credito->id)->pagadas()->orderBy('numero_cuota')->pluck('numero_cuota')->all();
    expect($pagadas)->toBe([1, 2, 3]);

    $pendientes = CuotaCredito::where('credito_id', $this->credito->id)->pendientes()->count();
    expect($pendientes)->toBe(2)
        ->and($this->credito->fresh()->estado)->toBe('activo');
});

it('accrues mora on an individual overdue cuota even while the crédito itself is not vencido', function () {
    CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)
        ->update(['fecha_vencimiento' => now()->subDays(3)]);

    expect($this->credito->fresh()->estado)->toBe('activo');

    Sanctum::actingAs($this->asesor, ['*']);
    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 1])
        ->assertSuccessful()->json('data');

    // tasa_mora_diaria 1% × monto_total de la cuota × 3 días.
    $cuota = CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->first();
    $moraEsperada = bcmul(bcmul((string) $cuota->monto_total, '0.01', 4), '3', 2);
    expect($preview['mora'])->toBe($moraEsperada);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 1, 'monto_pagado' => $preview['total'], 'medio' => 'efectivo',
    ])->assertCreated();

    expect((string) $cuota->fresh()->mora_pagada)->toBe($moraEsperada)
        ->and($this->credito->fresh()->estado)->toBe('activo');
});

it('rejects pagando más cuotas de las que quedan pendientes', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 6, 'monto_pagado' => 1000, 'medio' => 'efectivo',
    ])->assertStatus(422);
});

it('auto-liquidates the crédito when the last cuota pendiente is paid', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 5])
        ->assertSuccessful()->json('data');
    expect($preview['es_ultima_cuota'])->toBeTrue();

    $response = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 5, 'monto_pagado' => $preview['total'], 'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('liquidado');

    $tipos = $this->credito->fresh()->documentos()->pluck('tipo')->all();
    expect($tipos)->toContain('carta_no_adeudo')
        ->and($tipos)->toContain('voucher_pago')
        ->and($tipos)->not->toContain('devolucion');
});

it('registers the excedente as vuelto when monto_pagado exceeds the total exacto', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 1])
        ->assertSuccessful()->json('data');

    $montoPagado = bcadd($preview['total'], '10.00', 2);
    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 1, 'monto_pagado' => $montoPagado, 'medio' => 'efectivo',
    ])->assertCreated();

    $cobro = Cobro::where('credito_id', $this->credito->id)->firstOrFail();
    expect((string) $cobro->vuelto)->toBe('10.00');
});

it('preview endpoint does not mutate any cuota', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 2])
        ->assertSuccessful();

    expect(CuotaCredito::where('credito_id', $this->credito->id)->pagadas()->count())->toBe(0)
        ->and(Cobro::where('credito_id', $this->credito->id)->count())->toBe(0);
});

it('amortizes a monto covering whole cuotas plus a fraction as an adelanto of the next cuota', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $cuotaTotal = (string) CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->value('monto_total');
    $monto = bcadd(bcmul($cuotaTotal, '2', 2), bcdiv($cuotaTotal, '2', 2), 2);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['monto_pagado' => $monto])
        ->assertSuccessful()->json('data');

    expect($preview['cuotas'])->toHaveCount(3)
        ->and($preview['cuotas'][2]['completa'])->toBeFalse()
        ->and($preview['vuelto'])->toBe('0.00');

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'monto_pagado' => $monto, 'medio' => 'efectivo',
    ])->assertCreated();

    $cuotas = CuotaCredito::where('credito_id', $this->credito->id)->orderBy('numero_cuota')->get();
    expect($cuotas->pluck('pagada_at')->map(fn ($v) => $v !== null)->all())->toBe([true, true, false, false, false])
        ->and((string) $cuotas[2]->monto_abonado)->toBe(bcdiv($cuotaTotal, '2', 2))
        ->and((string) Cobro::where('credito_id', $this->credito->id)->value('vuelto'))->toBe('0.00');
});

it('completes a cuota parcialmente abonada with a later pago and accumulates the abonos', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $cuotaTotal = (string) CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->value('monto_total');
    $mitad = bcdiv($cuotaTotal, '2', 2);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", ['monto_pagado' => $mitad, 'medio' => 'efectivo'])->assertCreated();
    expect(CuotaCredito::where('credito_id', $this->credito->id)->pagadas()->count())->toBe(0);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", ['monto_pagado' => bcsub($cuotaTotal, $mitad, 2), 'medio' => 'efectivo'])->assertCreated();

    $primera = CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->first();
    expect($primera->pagada_at)->not->toBeNull()
        ->and((string) $primera->monto_abonado)->toBe($cuotaTotal)
        ->and(Cobro::where('credito_id', $this->credito->id)->count())->toBe(2);
});

it('applies the amortization to the mora of an overdue cuota before its saldo, without charging that mora twice', function () {
    CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->update(['fecha_vencimiento' => now()->subDays(3)]);
    Sanctum::actingAs($this->asesor, ['*']);

    $cuota = CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->first();
    $mora = bcmul(bcmul((string) $cuota->monto_total, '0.01', 4), '3', 2);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", ['monto_pagado' => bcadd($mora, '10.00', 2), 'medio' => 'efectivo'])->assertCreated();

    $cuota = $cuota->fresh();
    expect((string) $cuota->mora_pagada)->toBe($mora)
        ->and((string) $cuota->monto_abonado)->toBe('10.00');

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 1])
        ->assertSuccessful()->json('data');

    expect($preview['mora'])->toBe('0.00')
        ->and($preview['total'])->toBe(bcsub((string) $cuota->monto_total, '10.00', 2));
});

it('returns the excedente as vuelto only when the amortization exceeds the whole remaining debt', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $deuda = (string) CuotaCredito::where('credito_id', $this->credito->id)->sum('monto_total');

    $response = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'monto_pagado' => bcadd($deuda, '7.00', 2), 'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('liquidado')
        ->and((string) Cobro::where('credito_id', $this->credito->id)->value('vuelto'))->toBe('7.00');
});

it('undoes an amortization pago reverting the abonos and reopening the cuotas it completed', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $cuotaTotal = (string) CuotaCredito::where('credito_id', $this->credito->id)->where('numero_cuota', 1)->value('monto_total');
    $monto = bcadd($cuotaTotal, '20.00', 2);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", ['monto_pagado' => $monto, 'medio' => 'efectivo'])->assertCreated();
    $cobro = Cobro::where('credito_id', $this->credito->id)->firstOrFail();

    $this->postJson("/api/cobros/{$cobro->id}/anular", ['motivo' => 'error de digitación'])->assertSuccessful();

    $cuotas = CuotaCredito::where('credito_id', $this->credito->id)->get();
    expect($cuotas->whereNotNull('pagada_at'))->toHaveCount(0)
        ->and($cuotas->sum(fn ($c) => (float) $c->monto_abonado))->toBe(0.0)
        ->and($cobro->fresh()->estado)->toBe('anulado')
        ->and($cobro->abonosCuotas()->count())->toBe(0);
});

it('rejects anulando an amortization pago once a later cobro touched the same cuota', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", ['monto_pagado' => 30, 'medio' => 'efectivo'])->assertCreated();
    $primero = Cobro::where('credito_id', $this->credito->id)->firstOrFail();
    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", ['monto_pagado' => 30, 'medio' => 'efectivo'])->assertCreated();

    $this->postJson("/api/cobros/{$primero->id}/anular", ['motivo' => 'error'])->assertStatus(422);
});

it('requires either numero_cuotas or monto_pagado to preview a pago', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", [])->assertStatus(422);
});
