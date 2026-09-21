<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
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
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_cuotas' => 12,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 5000]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

function desembolsarPrendarioConCuotas($test, int $numeroCuotas, int $monto = 1200): int
{
    Sanctum::actingAs($test->asesor, ['*']);
    $creditoId = $test->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$test->bien->id],
        'monto_prestamo' => $monto, 'tipo_cuota' => 'mensual', 'numero_cuotas' => $numeroCuotas,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($test->adminAgencia, ['*']);
    $test->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($test->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $test->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    $test->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    return $creditoId;
}

it('offers pago por cuotas only when the crédito has more than one cuota to pay', function () {
    $conCuotas = desembolsarPrendarioConCuotas($this, 4);

    $this->getJson("/api/creditos-prendarios/{$conCuotas}")
        ->assertSuccessful()
        ->assertJsonPath('data.permite_pago_cuotas', true)
        ->assertJsonPath('data.permite_refrendo', true)
        ->assertJsonPath('data.monto_pago_cuotas_sugerido.cuotas.0.numero_cuota', 1);

    $this->cliente->refresh();
    $bien2 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 5000]);
    $this->bien = $bien2;
    $unaCuota = desembolsarPrendarioConCuotas($this, 1);

    $this->getJson("/api/creditos-prendarios/{$unaCuota}")
        ->assertSuccessful()
        ->assertJsonPath('data.permite_pago_cuotas', false)
        ->assertJsonMissingPath('data.monto_pago_cuotas_sugerido');

    $this->postJson("/api/creditos-prendarios/{$unaCuota}/pagar-cuotas", ['numero_cuotas' => 1, 'monto_pagado' => 2000, 'medio' => 'efectivo'])
        ->assertUnprocessable();
});

it('pays N cuotas inside the same crédito without creating a sucesor', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);
    $antes = Credito::query()->count();

    $preview = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas-preview", ['numero_cuotas' => 2])
        ->assertSuccessful()->json('data');

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", [
        'numero_cuotas' => 2, 'monto_pagado' => $preview['total'], 'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.id'))->toBe($creditoId)
        ->and($response->json('data.estado'))->toBe('activo')
        ->and(Credito::query()->count())->toBe($antes)
        ->and(CuotaCredito::where('credito_id', $creditoId)->pagadas()->pluck('numero_cuota')->all())->toBe([1, 2])
        ->and(Cobro::where('credito_id', $creditoId)->value('operacion'))->toBe('pago_cuotas_diario');
});

it('amortizes a pago a cuenta over the cuotas, leaving an adelanto on the next one', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);

    $cuotaTotal = (string) CuotaCredito::where('credito_id', $creditoId)->where('numero_cuota', 1)->value('monto_total');
    $monto = bcadd($cuotaTotal, bcdiv($cuotaTotal, '2', 2), 2);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", ['monto_pagado' => $monto, 'medio' => 'efectivo'])->assertCreated();

    $cuotas = CuotaCredito::where('credito_id', $creditoId)->orderBy('numero_cuota')->get();
    expect($cuotas[0]->pagada_at)->not->toBeNull()
        ->and($cuotas[1]->pagada_at)->toBeNull()
        ->and((string) $cuotas[1]->monto_abonado)->toBe(bcdiv($cuotaTotal, '2', 2));
});

it('blocks refrendar and adendar once cuotas were paid, and liquidation only charges what is still owed', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);

    $sinPagar = $this->getJson("/api/creditos-prendarios/{$creditoId}")->json('data.monto_liquidacion_sugerido');
    expect($sinPagar['capital'])->toBe('1200.00');

    $preview = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas-preview", ['numero_cuotas' => 2])->json('data');
    $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", [
        'numero_cuotas' => 2, 'monto_pagado' => $preview['total'], 'medio' => 'efectivo',
    ])->assertCreated();

    $capitalPagado = (string) CuotaCredito::where('credito_id', $creditoId)->pagadas()->sum('monto_capital');

    $detalle = $this->getJson("/api/creditos-prendarios/{$creditoId}")
        ->assertJsonPath('data.permite_refrendo', false)
        ->assertJsonPath('data.permite_pago_cuotas', true)
        ->json('data.monto_liquidacion_sugerido');

    expect($detalle['capital'])->toBe(bcsub('1200.00', $capitalPagado, 2));

    $this->postJson("/api/creditos-prendarios/{$creditoId}/refrendar", ['monto_pagado' => 500, 'medio' => 'efectivo'])->assertUnprocessable();
    $this->postJson("/api/creditos-prendarios/{$creditoId}/adendar", ['monto_pagado' => 500, 'medio' => 'efectivo'])->assertUnprocessable();
});

it('moves to liquidado_pendiente with an acta de devolución when the last cuota is paid', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 3);

    $preview = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas-preview", ['numero_cuotas' => 3])
        ->assertSuccessful()->json('data');
    expect($preview['es_ultima_cuota'])->toBeTrue();

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", [
        'numero_cuotas' => 3, 'monto_pagado' => $preview['total'], 'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('liquidado_pendiente')
        ->and(Credito::find($creditoId)->documentos()->pluck('tipo')->all())->toContain('devolucion');
});

it('undoes a pago por cuotas of a prendario crédito', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", ['monto_pagado' => 300, 'medio' => 'efectivo'])->assertCreated();
    $cobro = Cobro::where('credito_id', $creditoId)->firstOrFail();

    $this->postJson("/api/cobros/{$cobro->id}/anular", ['motivo' => 'error'])->assertSuccessful();

    expect(CuotaCredito::where('credito_id', $creditoId)->pagadas()->count())->toBe(0)
        ->and((float) CuotaCredito::where('credito_id', $creditoId)->sum('monto_abonado'))->toBe(0.0)
        ->and(Credito::find($creditoId)->estado)->toBe('activo');
});

it('returns the cobro_id of every payment so the client can open its voucher', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);

    $pagoCuotas = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", ['monto_pagado' => 300, 'medio' => 'efectivo'])
        ->assertCreated();

    $cobro = Cobro::query()->findOrFail($pagoCuotas->json('data.cobro_id'));
    expect($cobro->credito_id)->toBe($creditoId);

    $liquidar = $this->postJson("/api/creditos-prendarios/{$creditoId}/liquidar", [
        'monto_pagado' => $this->getJson("/api/creditos-prendarios/{$creditoId}")->json('data.monto_liquidacion_sugerido.total'),
        'medio' => 'efectivo',
    ])->assertSuccessful();

    expect(Cobro::query()->findOrFail($liquidar->json('data.cobro_id'))->operacion)->toBe('liquidacion');
});

it('serves the voucher PDF of a cobro generated by the backend, for a payment por cuotas and for a refrendo', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);

    $cobroCuotasId = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", ['monto_pagado' => 90, 'medio' => 'efectivo'])
        ->assertCreated()->json('data.cobro_id');

    $this->get("/api/cobros/{$cobroCuotasId}/voucher")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');

    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 5000]);
    $otroCredito = desembolsarPrendarioConCuotas($this, 1);
    $refrendo = $this->postJson("/api/creditos-prendarios/{$otroCredito}/refrendar", [
        'monto_pagado' => $this->getJson("/api/creditos-prendarios/{$otroCredito}")->json('data.monto_refrendo_sugerido.total'),
        'medio' => 'efectivo',
    ])->assertCreated();

    $this->get('/api/cobros/'.$refrendo->json('data.cobro_id').'/voucher')
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

it('links each voucher document to its cobro', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);

    $cobroId = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", ['monto_pagado' => 90, 'medio' => 'efectivo'])
        ->assertCreated()->json('data.cobro_id');

    $voucher = Credito::find($creditoId)->documentos()->where('tipo', 'voucher_pago')->firstOrFail();

    expect($voucher->cobro_id)->toBe($cobroId)
        ->and($voucher->datos['cuotas'][0]['abono'])->not->toBeNull();
});

it('returns the shareable text of the voucher from the backend', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);

    $cobroId = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", ['monto_pagado' => 90, 'medio' => 'efectivo'])
        ->assertCreated()->json('data.cobro_id');

    $texto = $this->getJson("/api/cobros/{$cobroId}/voucher/texto")->assertSuccessful()->json('data.texto');

    expect($texto)->toContain('COMPROBANTE DE COBRO')
        ->and($texto)->toContain("#{$cobroId}")
        ->and($texto)->toContain('Operación: Pago de cuotas')
        ->and($texto)->toContain('Monto pagado: S/ 90.00');
});

it('denies the voucher of a cobro the actor cannot see', function () {
    $creditoId = desembolsarPrendarioConCuotas($this, 4);

    $cobroId = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", ['monto_pagado' => 90, 'medio' => 'efectivo'])
        ->assertCreated()->json('data.cobro_id');

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $ajeno = User::factory()->forAgencia($otraAgencia)->create();
    $ajeno->assignRole('asesor');

    Sanctum::actingAs($ajeno, ['*']);
    $this->get("/api/cobros/{$cobroId}/voucher")->assertForbidden();
    $this->getJson("/api/cobros/{$cobroId}/voucher/texto")->assertForbidden();
});
