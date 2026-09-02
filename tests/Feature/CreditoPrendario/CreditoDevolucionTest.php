<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\DocumentoCredito;
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
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
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

it('liquidar deja el crédito en liquidado_pendiente y genera el acta de devolución', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id], 'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
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

    $liquidacionSugerida = $this->getJson("/api/creditos-prendarios/{$creditoId}")
        ->json('data.monto_liquidacion_sugerido.total');

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/liquidar", [
        'monto_pagado' => $liquidacionSugerida, 'medio' => 'efectivo',
    ])->assertSuccessful();

    expect($response->json('data.estado'))->toBe('liquidado_pendiente');

    $documentos = collect($response->json('data.documentos'));
    $devolucion = $documentos->firstWhere('tipo', 'devolucion');
    expect($devolucion)->not->toBeNull()
        ->and($devolucion['firmado_at'])->toBeNull();

    // El bien sigue indisponible mientras el crédito está liquidado_pendiente.
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id], 'monto_prestamo' => 300, 'tipo_cuota' => 'mensual',
    ])->assertUnprocessable();

    $verResponse = $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$devolucion['id']}/ver");
    $verResponse->assertSuccessful();
    expect($verResponse->headers->get('content-type'))->toContain('application/pdf');
});

it('recién queda liquidado y libera los bienes al subir el acta de devolución firmada', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id], 'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
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

    $liquidacionSugerida = $this->getJson("/api/creditos-prendarios/{$creditoId}")
        ->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$creditoId}/liquidar", [
        'monto_pagado' => $liquidacionSugerida, 'medio' => 'efectivo',
    ])->assertSuccessful();

    $devolucion = Credito::find($creditoId)->documentos()->where('tipo', 'devolucion')->firstOrFail();

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$devolucion->id}/subir-firmado", [
        'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
    ])->assertSuccessful();

    expect($response->json('data.firmado_at'))->not->toBeNull();

    $credito = Credito::find($creditoId);
    expect($credito->estado)->toBe('liquidado')
        ->and($this->bien->fresh()->estado)->toBe('recuperado');

    // El bien vuelve a estar disponible para un nuevo crédito.
    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id], 'monto_prestamo' => 300, 'tipo_cuota' => 'mensual',
    ])->assertCreated();
});

it('firmar cualquier otro documento (no la devolución) no liquida un crédito liquidado_pendiente', function () {
    Storage::fake('public');

    $activo = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    $contrato = DocumentoCredito::query()->create([
        'credito_id' => $activo->id,
        'empresa_id' => $this->empresa->id,
        'tipo' => 'contrato',
        'generado_por' => $this->asesor->id,
        'generado_at' => now(),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$activo->id}/liquidar", ['monto_pagado' => 1000000, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $this->postJson("/api/creditos-prendarios/{$activo->id}/documentos/{$contrato->id}/subir-firmado", [
        'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
    ])->assertSuccessful();

    expect(Credito::find($activo->id)->estado)->toBe('liquidado_pendiente');
});
