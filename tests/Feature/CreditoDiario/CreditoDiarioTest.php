<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoDiario\Models\CreditoDiarioGarantia;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05, 'max_cuotas' => 45,
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
});

function aprobarYFirmarDocumentosDiario(TestCase $test, int $creditoId): void
{
    Sanctum::actingAs($test->adminAgencia, ['*']);
    $test->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($test->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $test->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }
}

it('registers a diario crédito without any garantía real, just a cliente_id', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.tipo_credito'))->toBe('diario')
        ->and($response->json('data.tipo_cuota'))->toBe('diario')
        ->and($response->json('data.cliente.id'))->toBe($this->cliente->id);

    $credito = Credito::find($response->json('data.id'));
    expect($credito->bienes()->count())->toBe(0)
        ->and($credito->vehiculos()->count())->toBe(0)
        ->and($credito->inmuebles()->count())->toBe(0)
        ->and($credito->garantiasDiarias()->count())->toBe(1);
});

it('generates no contrato/declaracion/fotos/sticker for a diario crédito at registro — only the pagaré, once desembolsado', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
    ])->assertCreated()->json('data.id');

    $tiposAlRegistrar = Credito::find($creditoId)->documentos()->pluck('tipo')->all();
    expect($tiposAlRegistrar)->toBe([]);

    aprobarYFirmarDocumentosDiario($this, $creditoId);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $tiposAlDesembolsar = Credito::find($creditoId)->documentos()->pluck('tipo')->all();
    expect($tiposAlDesembolsar)->toBe(['voucher_desembolso', 'pagare']);

    $pagare = Credito::find($creditoId)->documentos()->where('tipo', 'pagare')->firstOrFail();
    $response = $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$pagare->id}/ver");
    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('runs the full aprobar -> desembolsar -> cronograma lifecycle with tipo_cuota diario', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 300,
        'tipo_cuota' => 'diario',
    ])->assertCreated()->json('data.id');

    aprobarYFirmarDocumentosDiario($this, $creditoId);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $credito = Credito::find($creditoId);
    expect($credito->estado)->toBe('activo')
        ->and($credito->tipo_cuota)->toBe('diario');

    // tipo_cuota diario -> CUOTAS_POR_TIPO default de 30 cuotas.
    expect($credito->cuotas)->toHaveCount(30);
});

it('lets the asesor pick tipo_cuota semanal for a diario crédito, spanning the full month (30 días) instead of 28', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 280,
        'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    aprobarYFirmarDocumentosDiario($this, $creditoId);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $credito = Credito::find($creditoId);
    expect($credito->tipo_cuota)->toBe('semanal')
        ->and($credito->plazo_dias)->toBe(30)
        ->and($credito->fecha_vencimiento->toDateString())->toBe($credito->fecha_desembolso->copy()->addDays(30)->toDateString());

    $cuotas = $credito->cuotas()->orderBy('numero_cuota')->get();
    expect($cuotas)->toHaveCount(4);

    // 7, 14, 21 días para las 3 primeras — la última absorbe los 2 días de
    // más para que las 4 cuotas semanales abarquen el mes completo (30).
    foreach ([1 => 7, 2 => 14, 3 => 21] as $numero => $dias) {
        $cuota = $cuotas->firstWhere('numero_cuota', $numero);
        expect($cuota->fecha_vencimiento->toDateString())->toBe($credito->fecha_desembolso->copy()->addDays($dias)->toDateString());
    }

    $ultima = $cuotas->firstWhere('numero_cuota', 4);
    expect($ultima->fecha_vencimiento->toDateString())->toBe($credito->fecha_desembolso->copy()->addDays(30)->toDateString());
});

it('does not adjust semanal to span the month when the asesor picks an explicit numero_cuotas (not the default)', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 280,
        'tipo_cuota' => 'semanal',
        'numero_cuotas' => 8,
    ])->assertCreated()->json('data.id');

    aprobarYFirmarDocumentosDiario($this, $creditoId);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $credito = Credito::find($creditoId);
    // Fórmula genérica: 8 cuotas × 7 días = 56 días, sin ajuste (solo aplica
    // a las 4 cuotas por defecto).
    expect($credito->plazo_dias)->toBe(56)
        ->and($credito->cuotas)->toHaveCount(8);
});

it('honors numero_cuotas up to the configured max_cuotas (45) for diario', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 300,
        'tipo_cuota' => 'diario',
        'numero_cuotas' => 46,
    ])->assertUnprocessable();

    $response = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 300,
        'tipo_cuota' => 'diario',
        'numero_cuotas' => 45,
    ])->assertCreated();

    expect(Credito::find($response->json('data.id'))->numero_cuotas)->toBe(45);
});

it('allows an admin to raise monto_prestamo above the placeholder garantía valorización (no prendario-style cap)', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 300,
        'tipo_cuota' => 'diario',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", [
        'monto_prestamo' => 5000,
    ])->assertSuccessful()->assertJsonPath('data.monto_prestamo', '5000.00');
});

it('denies refrendar for a diario crédito: use pagar-cuotas instead', function () {
    $original = Credito::factory()->diario()
        ->activo()
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
        ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$original->id}/refrendar", ['monto_pagado' => 100, 'medio' => 'efectivo'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Los créditos diarios no se refrendan: usa "pagar cuotas" para pagar cuotas pendientes.');

    expect($original->fresh()->estado)->toBe('activo');
});

it('denies adendar for a diario crédito: use pagar-cuotas instead', function () {
    $original = Credito::factory()->diario()
        ->activo()
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
        ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$original->id}/adendar", ['monto_pagado' => 100, 'medio' => 'efectivo'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Los créditos diarios no se adendan: usa "pagar cuotas" para pagar cuotas pendientes.');

    expect($original->fresh()->estado)->toBe('activo');
});

it('liquidates a diario crédito straight to liquidado, skipping the acta de devolución that other types use', function () {
    $credito = Credito::factory()->diario()
        ->activo()
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
            'monto_prestamo' => 1000,
        ]);

    $garantia = CreditoDiarioGarantia::factory()->create([
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'valorizacion' => 1000,
        'estado' => 'en_garantia',
    ]);
    $credito->garantiasComo(CreditoDiarioGarantia::class)->attach($garantia->id);

    Sanctum::actingAs($this->asesor, ['*']);

    $sugerido = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');

    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $sugerido, 'medio' => 'efectivo'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'liquidado');

    $tipos = $credito->fresh()->documentos()->pluck('tipo')->all();
    expect($tipos)->toContain('carta_no_adeudo')
        ->and($tipos)->toContain('voucher_pago')
        ->and($tipos)->not->toContain('devolucion');

    expect($garantia->fresh()->estado)->toBe('recuperado');
});

it('keeps a vencido diario crédito past its período de espera in vencido — never moves to en_venta', function () {
    $credito = Credito::factory()->diario()
        ->vencido(diasVencido: 40)
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
        ]);

    app(CreditoService::class)->actualizarEstadosVencidos();

    expect($credito->fresh()->estado)->toBe('vencido');
});

it('rejects enviarATienda and hides puede_enviar_tienda for a vencido diario crédito', function () {
    $credito = Credito::factory()->diario()
        ->vencido(diasVencido: 40)
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
        ]);

    Sanctum::actingAs($this->adminAgencia, ['*']);

    $show = $this->getJson("/api/creditos-prendarios/{$credito->id}")->assertSuccessful();
    expect($show->json('data.puede_enviar_tienda'))->toBeFalse();

    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", ['precios' => []])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Este tipo de crédito no se envía a la tienda: no tiene garantía que rematar.');
});
