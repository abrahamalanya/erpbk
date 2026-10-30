<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Services\PdfGeneratorService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('public');
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

/**
 * Registers, aprueba, firma documentos y desembolsa un crédito semanal
 * (4 cuotas por defecto, 7 días cada una) para tener varias cuotas que
 * revisar; retorna su id. tipo_cuota semanal en vez de pasar numero_cuotas
 * a propósito: pasarlo exige el permiso creditos_prendarios.editar además
 * de desembolsar, que el asesor no tiene.
 */
function desembolsarCreditoDeTres($test): int
{
    Sanctum::actingAs($test->asesor, ['*']);
    $creditoId = $test->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$test->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'semanal',
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

it('lets an admin correct the fecha de desembolso of an activo crédito and shifts the cronograma', function () {
    $creditoId = desembolsarCreditoDeTres($this);
    $montosOriginales = Credito::find($creditoId)->cuotas->pluck('monto_total', 'numero_cuota');

    $nuevaFecha = now()->subDays(45)->toDateString();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => $nuevaFecha,
    ])->assertSuccessful();

    $credito = Credito::find($creditoId)->load('cuotas');

    expect($credito->fecha_desembolso->toDateString())->toBe($nuevaFecha)
        ->and($credito->fecha_vencimiento->toDateString())->toBe(now()->subDays(45)->addDays(28)->toDateString());

    foreach ($credito->cuotas as $cuota) {
        // Cada cuota semanal queda a 7 días de la nueva fecha de desembolso, por número de cuota.
        expect($cuota->fecha_vencimiento->toDateString())
            ->toBe(now()->subDays(45)->addDays(7 * $cuota->numero_cuota)->toDateString());
    }

    // Los montos de las cuotas no cambian, solo las fechas.
    expect($credito->cuotas->pluck('monto_total', 'numero_cuota')->all())->toBe($montosOriginales->all());
});

it('transitions an activo crédito straight to vencido when the corrected fecha leaves it overdue, charging mora immediately', function () {
    $creditoId = desembolsarCreditoDeTres($this);

    $nuevaFecha = now()->subDays(45)->toDateString();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => $nuevaFecha,
    ])->assertSuccessful();

    $credito = Credito::find($creditoId);
    expect($credito->estado)->toBe('vencido')
        ->and($credito->dias_en_mora)->toBeGreaterThan(0);

    // tasa_mora_diaria=1%, monto_prestamo=500, 17 días vencido (45 - 28 de plazo).
    $sugerido = $this->getJson("/api/creditos-prendarios/{$creditoId}")->json('data.monto_liquidacion_sugerido');
    expect($sugerido['dias_mora'])->toBe($credito->dias_en_mora)
        ->and(bccomp($sugerido['mora'], '0.00', 2))->toBe(1);
});

it('reverts a vencido crédito back to activo when the corrected fecha clears the overdue period, dropping the mora', function () {
    $creditoId = desembolsarCreditoDeTres($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->subDays(45)->toDateString(),
    ])->assertSuccessful();
    expect(Credito::find($creditoId)->estado)->toBe('vencido');

    // Corrige de nuevo a una fecha reciente: el crédito ya no está vencido.
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->subDays(5)->toDateString(),
    ])->assertSuccessful();

    $credito = Credito::find($creditoId);
    expect($credito->estado)->toBe('activo')
        ->and($credito->dias_en_mora)->toBe(0);
});

it('lets an admin fix the fecha de desembolso while pendiente, without touching cuotas', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    $fecha = now()->subDays(10)->toDateString();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => $fecha,
    ])->assertSuccessful();

    $credito = Credito::find($creditoId);
    expect($credito->fecha_desembolso->toDateString())->toBe($fecha)
        ->and($credito->fecha_vencimiento)->toBeNull()
        ->and($credito->cuotas()->exists())->toBeFalse()
        ->and($credito->estado)->toBe('pendiente');
});

it('desembolsa using the fecha planificada set while pendiente when none is given', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    $fecha = now()->subDays(12)->toDateString();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => $fecha,
    ])->assertSuccessful();
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    // Desembolso SIN enviar fecha: debe tomar la planificada.
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $credito = Credito::find($creditoId)->load('cuotas');
    expect($credito->fecha_desembolso->toDateString())->toBe($fecha)
        ->and($credito->cuotas->first()->fecha_vencimiento->toDateString())
        ->toBe(now()->subDays(12)->addDays(7)->toDateString());
});

it('denies editing the fecha de desembolso once the crédito was refrendado', function () {
    $creditoId = desembolsarCreditoDeTres($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $interes = app(CreditoService::class)
        ->calcularMontoRefrendo(Credito::find($creditoId))['interes'];

    $this->postJson("/api/creditos-prendarios/{$creditoId}/refrendar", [
        'monto_pagado' => $interes, 'medio' => 'efectivo',
    ])->assertCreated();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->subDays(10)->toDateString(),
    ])->assertUnprocessable();
});

it('rejects a fecha de desembolso in the future', function () {
    $creditoId = desembolsarCreditoDeTres($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->addDay()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['fecha_desembolso']);
});

it('denies asesor from editing the fecha de desembolso', function () {
    $creditoId = desembolsarCreditoDeTres($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->subDays(10)->toDateString(),
    ])->assertForbidden();
});

it('previews every daily installment from the edited date instead of today', function () {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00'));
    Sanctum::actingAs($this->adminAgencia);
    $this->postJson('/api/creditos-prendarios/cronograma-preview', [
        'monto_prestamo' => 500, 'interes' => 15, 'tipo_cuota' => 'diario', 'tipo_credito' => 'diario',
        'numero_cuotas' => 4, 'fecha_desembolso' => '2026-10-20',
    ])->assertSuccessful()->assertJsonPath('data.fecha_base', '2026-10-20')
        ->assertJsonPath('data.cuotas.0.fecha_vencimiento', '2026-10-21')
        ->assertJsonPath('data.cuotas.1.fecha_vencimiento', '2026-10-22')
        ->assertJsonPath('data.cuotas.2.fecha_vencimiento', '2026-10-23')
        ->assertJsonPath('data.cuotas.3.fecha_vencimiento', '2026-10-24');
});

it('corrects every persisted date while preserving installments, payments and unrelated credits', function ($frecuencia, $tipoInteres, $plazo, $esperadas) {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00'));
    $credito = Credito::factory()->diario()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id, 'registrado_por' => $this->asesor->id,
        'cliente_id' => $this->cliente->id, 'estado' => 'activo', 'tipo_cuota' => $frecuencia, 'tipo_interes' => $tipoInteres,
        'tipo_credito' => $tipoInteres === 'compuesto' ? 'hipotecario' : 'diario',
        'monto_prestamo' => 500, 'interes' => 15, 'numero_cuotas' => 4, 'plazo_dias' => $plazo,
        'fecha_desembolso' => '2026-10-30', 'fecha_vencimiento' => now()->addDays($plazo),
    ]);
    $preview = app(CreditoService::class)->previsualizarCronograma('500', '15', $frecuencia, 4, $tipoInteres, $credito->tipo_credito, fechaDesembolso: '2026-10-30');
    foreach ($preview['cuotas'] as $fila) {
        $credito->cuotas()->create([...$fila, 'empresa_id' => $this->empresa->id, 'monto_abonado' => $fila['numero_cuota'] === 2 ? '10.00' : '0.00', 'pagada_at' => $fila['numero_cuota'] === 1 ? now() : null]);
    }
    $datosProtegidos = fn ($c) => Arr::except($c->getAttributes(), ['fecha_vencimiento', 'updated_at']);
    $originales = $credito->cuotas()->orderBy('numero_cuota')->get()->map($datosProtegidos)->all();
    $otro = Credito::factory()->create(['empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    $historico = $otro->fresh()->getAttributes();
    Sanctum::actingAs($this->adminAgencia);
    $response = $this->postJson("/api/creditos-prendarios/{$credito->id}/actualizar-fecha-desembolso", ['fecha_desembolso' => '2026-10-20'])->assertSuccessful();
    $cuotas = $credito->cuotas()->orderBy('numero_cuota')->get();
    expect($cuotas->map(fn ($c) => $c->fecha_vencimiento->toDateString())->all())->toBe($esperadas)
        ->and($cuotas->map($datosProtegidos)->all())->toBe($originales)
        ->and($otro->fresh()->getAttributes())->toBe($historico);
    foreach ($response->json('data.cuotas') as $fila) {
        expect(substr($fila['fecha_vencimiento'], 0, 10))->toBe($esperadas[$fila['numero_cuota'] - 1]);
    }
})->with([
    'diario' => ['diario', 'simple', 4, ['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24']],
    'semanal con ultima cuota a 30 dias' => ['semanal', 'simple', 30, ['2026-10-27', '2026-11-03', '2026-11-10', '2026-11-19']],
    'quincenal' => ['quincenal', 'simple', 60, ['2026-11-04', '2026-11-19', '2026-12-04', '2026-12-19']],
    'mensual compuesto' => ['mensual', 'compuesto', 120, ['2026-11-20', '2026-12-20', '2027-01-20', '2027-02-20']],
]);

it('uses the planned date in the PDF shared by web and app without persisting tentative installments', function () {
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00'));
    $credito = Credito::factory()->diario()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id, 'registrado_por' => $this->asesor->id,
        'cliente_id' => $this->cliente->id, 'monto_prestamo' => 500, 'interes' => 15, 'numero_cuotas' => 4,
    ]);
    Sanctum::actingAs($this->adminAgencia);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/actualizar-fecha-desembolso", ['fecha_desembolso' => '2026-10-20'])->assertSuccessful();
    $this->mock(PdfGeneratorService::class, function ($mock) {
        $mock->shouldReceive('renderizarDesdeVista')->once()->withArgs(function ($vista, $datos) {
            expect($datos['tentativo'])->toBeTrue()
                ->and($datos['credito']->cuotas->map(fn ($c) => $c->fecha_vencimiento->toDateString())->all())->toBe(['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24']);

            return true;
        })->andReturn(response('cronograma'));
    });
    $this->get("/api/creditos-prendarios/{$credito->id}/cronograma/ver")->assertSuccessful();
    expect($credito->cuotas()->exists())->toBeFalse();
});
