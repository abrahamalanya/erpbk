<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
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
        'tipo_credito' => 'vehicular',
        'interes_default' => 12, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'prendario',
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 50000, 'abierta_at' => now(),
    ]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->vehiculo = Vehiculo::factory()->paraCliente($this->cliente)->create(['valorizacion' => 20000]);
    $this->vehiculo2 = Vehiculo::factory()->paraCliente($this->cliente)->create(['valorizacion' => 15000]);
});

it('registers a vehicular crédito over several vehículos with a supervisor', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id, $this->vehiculo2->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 12000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.tipo_credito'))->toBe('vehicular')
        ->and($response->json('data.interes'))->toBe('12.00')
        ->and($response->json('data.supervisado_por.id'))->toBe($this->adminAgencia->id);

    $credito = Credito::find($response->json('data.id'));
    expect($credito->vehiculos()->count())->toBe(2)
        ->and($this->vehiculo->fresh()->estado)->toBe('en_garantia');
});

it('rejects a vehicular crédito when the cliente has no dirección/referencia', function () {
    $this->cliente->update(['direccion' => null, 'referencia' => null]);
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 5000,
        'tipo_cuota' => 'mensual',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Para un crédito vehicular el cliente debe tener dirección y referencia registradas.');
});

it('requires a supervisado_por on a vehicular crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id],
        'monto_prestamo' => 5000,
        'tipo_cuota' => 'mensual',
    ])->assertStatus(422)->assertJsonValidationErrors('supervisado_por');
});

it('rejects a supervisado_por that is not an admin de agencia or supervisor', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id],
        'supervisado_por' => $this->asesor->id,
        'monto_prestamo' => 5000,
        'tipo_cuota' => 'mensual',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'El supervisor indicado debe ser un administrador de agencia o supervisor de la empresa.');
});

it('routes a vencido vehicular crédito through pendiente_conformidad before the tienda', function () {
    Storage::fake('public');

    $credito = Credito::factory()->paraVehiculo($this->vehiculo)
        ->vencido(diasVencido: 20)
        ->create([
            'registrado_por' => $this->asesor->id,
            'supervisado_por' => $this->adminAgencia->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
        ]);

    // El cron NO lo manda a la tienda: queda pendiente de conformidad.
    app(CreditoService::class)->actualizarEstadosVencidos();
    expect($credito->fresh()->estado)->toBe('pendiente_conformidad')
        ->and($this->vehiculo->fresh()->estado)->toBe('en_garantia');

    Sanctum::actingAs($this->adminAgencia, ['*']);

    // Enviar a tienda todavía no se puede: falta la conformidad.
    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", ['precios' => [$this->vehiculo->id => 18000]])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Debes registrar la conformidad del notario/abogado antes de enviar el crédito a la tienda.');

    // Se registra la conformidad (PDF del notario/abogado).
    $this->postJson("/api/creditos-prendarios/{$credito->id}/conformidad", [
        'archivo' => UploadedFile::fake()->create('conformidad.pdf', 40, 'application/pdf'),
    ])->assertSuccessful();

    expect($credito->fresh()->conformidad_confirmada_at)->not->toBeNull()
        ->and($credito->fresh()->conformidad_path)->not->toBeNull()
        ->and($credito->fresh()->estado)->toBe('pendiente_conformidad');

    // Ahora sí pasa a la tienda, con precio por vehículo.
    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", ['precios' => [$this->vehiculo->id => 18000]])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'en_venta');

    expect($this->vehiculo->fresh()->estado)->toBe('disponible_venta')
        ->and($this->vehiculo->fresh()->precio_venta)->toBe('18000.00');
});

it('keeps prendario going straight to en_venta (no conformidad step)', function () {
    $bien = Bien::factory()->paraCliente($this->cliente)->create();
    $credito = Credito::factory()->paraBien($bien)
        ->vencido(diasVencido: 20)
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    app(CreditoService::class)->actualizarEstadosVencidos();

    expect($credito->fresh()->estado)->toBe('en_venta');
});

it('runs the full lifecycle on a vehicular crédito through the shared endpoints', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->asesor, ['*']);

    $creditoId = $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 8000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    // aprobar + firmar documentos
    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();
    Sanctum::actingAs($this->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $doc) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$doc->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('f.pdf', 50, 'application/pdf'),
        ])->assertSuccessful();
    }

    // desembolsar -> activo + cronograma + egreso en caja
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'activo');

    $credito = Credito::find($creditoId);
    expect($credito->cuotas)->toHaveCount(1)
        ->and($credito->vehiculos()->count())->toBe(1);

    // el contrato vehicular renderiza con los datos del vehículo
    $contrato = $credito->documentos()->where('tipo', 'contrato')->firstOrFail();
    $pdf = $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$contrato->id}/ver")->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');

    // refrendar -> sucesor encadenado, mismo tipo
    $interes = app(CreditoService::class)->calcularMontoRefrendo($credito->fresh())['interes'];
    $nuevo = $this->postJson("/api/creditos-prendarios/{$creditoId}/refrendar", [
        'monto_pagado' => $interes, 'medio' => 'efectivo',
    ])->assertCreated()->json('data');

    expect($nuevo['tipo_credito'])->toBe('vehicular')
        ->and($nuevo['estado'])->toBe('activo');
});
