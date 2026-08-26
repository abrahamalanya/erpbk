<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
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
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
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

function aprobarYFirmarDocumentos(TestCase $test, int $creditoId): void
{
    Sanctum::actingAs($test->adminAgencia, ['*']);
    $test->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($test->asesor, ['*']);
    foreach (CreditoPrendario::find($creditoId)->documentos as $documento) {
        $test->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }
}

it('defaults numero_cuotas from the fixed table per tipo_cuota (semanal -> 4)', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 400, 'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    aprobarYFirmarDocumentos($this, $creditoId);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $cuotas = CreditoPrendario::find($creditoId)->cuotas;
    expect($cuotas)->toHaveCount(4);

    // Capital amortizado en 4 partes iguales (100 c/u); interés fijo en
    // cada cuota, calculado sobre el monto_prestamo original completo (400)
    // × 10% × 7 días / 30 -> 9.33 por cuota, 4 cuotas -> 37.32.
    $sumaCapital = number_format((float) $cuotas->sum('monto_capital'), 2, '.', '');
    $sumaInteres = number_format((float) $cuotas->sum('monto_interes'), 2, '.', '');
    expect($sumaCapital)->toBe('400.00')
        ->and($sumaInteres)->toBe('37.32');
});

it('streams a freshly rendered cronograma PDF for a desembolsado crédito', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 400, 'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    aprobarYFirmarDocumentos($this, $creditoId);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $response = $this->get("/api/creditos-prendarios/{$creditoId}/cronograma/ver");

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('allows an admin to override numero_cuotas and interes at desembolso time', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 400, 'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    aprobarYFirmarDocumentos($this, $creditoId);

    $cajaAdmin = Caja::factory()->create(['user_id' => $this->adminAgencia->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $cajaAdmin->id, 'empresa_id' => $cajaAdmin->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar", [
        'numero_cuotas' => 8,
        'interes' => 12,
    ])->assertSuccessful();

    expect($response->json('data.interes'))->toBe('12.00');

    // semanal -> periodo fijo de 7 días; 8 cuotas = 56 días reales, no los
    // mismos 30 días originales repartidos en 8 (confirmado explícitamente:
    // cada cuota es un periodo completo, el plazo se extiende).
    $credito = CreditoPrendario::find($creditoId);
    expect($credito->plazo_dias)->toBe(56)
        ->and($credito->fecha_vencimiento->toDateString())->toBe($credito->fecha_desembolso->copy()->addDays(56)->toDateString());

    $cuotas = $credito->cuotas;
    expect($cuotas)->toHaveCount(8);
    expect($cuotas->last()->fecha_vencimiento->toDateString())->toBe($credito->fecha_desembolso->copy()->addDays(56)->toDateString());
});

it('denies a non-admin (asesor) from overriding numero_cuotas/interes at desembolso, even though asesor can desembolsar', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 400, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    aprobarYFirmarDocumentos($this, $creditoId);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar", ['numero_cuotas' => 3])
        ->assertForbidden();

    // Without the override, the asesor can still desembolsar normally.
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();
});

it('exposes monto_liquidacion_sugerido on show() and rejects liquidar with an insufficient monto_pagado', function () {
    Storage::fake('public');

    ConfiguracionCreditoPrendario::query()->where('empresa_id', $this->empresa->id)->update(['interes_default' => 20]);

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 1000, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    aprobarYFirmarDocumentos($this, $creditoId);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    // Día 0 desde el desembolso -> se cobra el mínimo configurado (15 días).
    $show = $this->getJson("/api/creditos-prendarios/{$creditoId}")->assertSuccessful();
    expect($show->json('data.monto_liquidacion_sugerido.total'))->toBe('1100.00');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/liquidar", ['monto_pagado' => 1000, 'medio' => 'efectivo'])
        ->assertUnprocessable();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/liquidar", ['monto_pagado' => 1100, 'medio' => 'efectivo'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'liquidado_pendiente');
});
