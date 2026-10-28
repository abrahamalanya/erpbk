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
    $cajaAdmin = Caja::factory()->create(['user_id' => $this->adminGeneral->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $cajaAdmin->id, 'empresa_id' => $cajaAdmin->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);

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

it('lets administrador_general condonar mora/interés regularizando varias cuotas atrasadas de un diario', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 2])
        ->assertSuccessful()->json('data');

    $descuento = '5.00';
    $montoPagado = bcsub($preview['total'], $descuento, 2);

    $response = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 2,
        'monto_pagado' => $montoPagado,
        'medio' => 'efectivo',
        'descuento' => $descuento,
        'motivo_descuento' => 'cliente regulariza atraso, se condona parte de la mora',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('activo');

    $pagadas = CuotaCredito::where('credito_id', $this->credito->id)->pagadas()->orderBy('numero_cuota')->pluck('numero_cuota')->all();
    expect($pagadas)->toBe([1, 2]);

    $cobro = Cobro::where('credito_id', $this->credito->id)->firstOrFail();
    expect((string) $cobro->monto_pagado)->toBe($montoPagado)
        ->and((string) $cobro->descuento)->toBe($descuento)
        ->and($cobro->motivo_descuento)->toBe('cliente regulariza atraso, se condona parte de la mora');
});

it('denies an asesor from applying a descuento while regularizando cuotas', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 2])
        ->assertSuccessful()->json('data');

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 2,
        'monto_pagado' => bcsub($preview['total'], '5.00', 2),
        'medio' => 'efectivo',
        'descuento' => '5.00',
        'motivo_descuento' => 'intento de asesor',
    ])->assertStatus(422);

    expect(Cobro::where('credito_id', $this->credito->id)->count())->toBe(0);
});

it('denies a descuento al pagar cuotas de un crédito que no es diario, incluso para administrador_general', function () {
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
    $preview = $this->postJson("/api/creditos-prendarios/{$prendarioId}/pagar-cuotas-preview", ['numero_cuotas' => 1])
        ->assertSuccessful()->json('data');

    $this->postJson("/api/creditos-prendarios/{$prendarioId}/pagar-cuotas", [
        'numero_cuotas' => 1,
        'monto_pagado' => bcsub($preview['total'], '1.00', 2),
        'medio' => 'efectivo',
        'descuento' => '1.00',
        'motivo_descuento' => 'no debería permitirse',
    ])->assertStatus(422);
});

it('requires a motivo_descuento when administrador_general aplica un descuento regularizando cuotas', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);

    $preview = $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas-preview", ['numero_cuotas' => 1])
        ->assertSuccessful()->json('data');

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'numero_cuotas' => 1,
        'monto_pagado' => bcsub($preview['total'], '1.00', 2),
        'medio' => 'efectivo',
        'descuento' => '1.00',
    ])->assertStatus(422);
});

it('denies a descuento amortizando un monto libre (sin numero_cuotas), incluso para administrador_general', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);

    $this->postJson("/api/creditos-prendarios/{$this->credito->id}/pagar-cuotas", [
        'monto_pagado' => '50.00',
        'medio' => 'efectivo',
        'descuento' => '5.00',
        'motivo_descuento' => 'no debería permitirse sin numero_cuotas',
    ])->assertStatus(422);
});
