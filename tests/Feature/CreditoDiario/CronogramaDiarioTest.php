<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
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

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

it('streams a tentative cronograma PDF for a diario crédito that has no cuotas yet', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
        'numero_cuotas' => 5,
    ])->assertCreated()->json('data.id');

    expect(Credito::find($creditoId)->cuotas()->exists())->toBeFalse();

    $response = $this->get("/api/creditos-prendarios/{$creditoId}/cronograma/ver");

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('renders the diario cronograma with abono/saldo/firma columns instead of capital/interés', function () {
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

    // Paga completa la primera cuota (abono = cuota, queda con fecha y saldo
    // en 0.00) y deja una segunda con un abono parcial (sin pagada_at), para
    // que las tres situaciones (pagada, parcial, pendiente) queden cubiertas
    // en la misma tabla.
    $preview = $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas-preview", ['numero_cuotas' => 1])
        ->assertSuccessful()->json('data');
    $this->postJson("/api/creditos-prendarios/{$creditoId}/pagar-cuotas", [
        'numero_cuotas' => 1, 'monto_pagado' => $preview['total'], 'medio' => 'efectivo',
    ])->assertCreated();

    $credito = Credito::find($creditoId);
    $segundaCuota = $credito->cuotas()->where('numero_cuota', 2)->firstOrFail();
    $segundaCuota->update(['monto_abonado' => '20.00']);

    $html = view('modules.credito-diario.documentos.cronograma', [
        'credito' => $credito->load(['cliente', 'agencia', 'empresa', 'registradoPor', 'cuotas']),
        'tentativo' => false,
        'fotoDataUri' => fn (?string $path, int $maxAncho = 900): ?string => null,
    ])->render();

    expect($html)->toContain('Fecha abono')
        ->toContain('>Abono<')
        ->toContain('>Saldo<')
        ->toContain('Firma')
        ->not->toContain('Capital')
        ->not->toContain('Interés');

    $pagada = $credito->cuotas()->where('numero_cuota', 1)->firstOrFail();
    expect((string) $pagada->monto_abonado)->toBe((string) $pagada->monto_total)
        ->and($pagada->pagada_at)->not->toBeNull();
    expect($html)->toContain($pagada->pagada_at->format('d/m/Y'));

    $parcial = $segundaCuota->fresh();
    expect((string) $parcial->monto_abonado)->toBe('20.00')
        ->and($parcial->pagada_at)->toBeNull();
    $saldoParcial = number_format((float) bcsub((string) $parcial->monto_total, '20.00', 2), 2);
    expect($html)->toContain('20.00')->toContain($saldoParcial);

    $response = $this->get("/api/creditos-prendarios/{$creditoId}/cronograma/ver");
    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('keeps the prendario cronograma with capital/interés columns, unaffected by the diario template', function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'prendario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_cuotas' => 45,
    ]);

    $bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 400, 'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    $response = $this->get("/api/creditos-prendarios/{$creditoId}/cronograma/ver");
    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf');

    $credito = Credito::find($creditoId);
    $html = view('modules.credito-prendario.documentos.cronograma', [
        'credito' => $credito->load(['cliente', 'agencia', 'empresa', 'registradoPor']),
        'tentativo' => true,
        'fotoDataUri' => fn (?string $path, int $maxAncho = 900): ?string => null,
    ])->render();

    expect($html)->toContain('Capital')
        ->toContain('Interés')
        ->not->toContain('Fecha abono')
        ->not->toContain('Firma');
});
