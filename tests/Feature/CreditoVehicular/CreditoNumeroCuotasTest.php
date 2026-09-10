<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'vehicular',
        'interes_default' => 12, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_cuotas' => 12,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'prendario',
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_cuotas' => 1,
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
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 2000]);
});

it('persists numero_cuotas at registration and derives plazo_dias from it (n × período)', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 12000,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 6,
    ])->assertCreated();

    expect($response->json('data.numero_cuotas'))->toBe(6)
        ->and($response->json('data.plazo_dias'))->toBe(180); // 6 × 30
});

it('rejects numero_cuotas above the tipo max_cuotas', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 12000,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 24,
    ])->assertStatus(422)->assertJsonValidationErrors('numero_cuotas');
});

it('ignores numero_cuotas for a tipo whose max_cuotas is 1 (prendario unchanged)', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 1000,
        'tipo_cuota' => 'semanal',
        'numero_cuotas' => 1,
    ])->assertCreated();

    // numero_cuotas queda null y el plazo sigue siendo el de la config (30),
    // no 7 (1 × período semanal).
    expect($response->json('data.numero_cuotas'))->toBeNull()
        ->and($response->json('data.plazo_dias'))->toBe(30);
});

it('shows the chosen numero_cuotas in the tentative cronograma (screen preview and PDF path)', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $creditoId = $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 12000,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 5,
    ])->assertCreated()->json('data.id');

    $credito = Credito::find($creditoId);
    expect($credito->cuotas()->exists())->toBeFalse();

    // Preview (mismo helper que consume el endpoint /cronograma/ver antes del desembolso).
    $preview = app(\App\Modules\Credito\Services\CreditoService::class)->previsualizarCronograma(
        (string) $credito->monto_prestamo,
        (string) $credito->interes,
        $credito->tipo_cuota,
        $credito->numero_cuotas,
    );
    expect($preview['cuotas'])->toHaveCount(5)
        ->and($preview['plazo_dias'])->toBe(150);

    // El PDF del cronograma se sirve y refleja las 5 filas.
    $credito->setRelation('cuotas', collect($preview['cuotas'])->map(fn (array $f) => new \App\Modules\Credito\Models\CuotaCredito($f)));
    $html = view('modules.credito-prendario.documentos.cronograma', ['credito' => $credito->load(['cliente', 'agencia', 'empresa']), 'tentativo' => true])->render();
    // 3 celdas .num por fila de cuota (capital, interés, cuota) -> 5 filas = 15+.
    expect(substr_count($html, 'class="num"'))->toBeGreaterThanOrEqual(15);

    $this->get("/api/creditos-prendarios/{$creditoId}/cronograma/ver")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('leaves numero_cuotas null when the asesor does not send it', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/creditos-vehiculares', [
        'vehiculo_ids' => [$this->vehiculo->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 12000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.numero_cuotas'))->toBeNull()
        ->and($response->json('data.plazo_dias'))->toBe(30);
});

it('exposes max_cuotas per tipo on the registro configuracion endpoint', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->getJson('/api/creditos-prendarios/configuracion')->assertSuccessful();

    expect($response->json('data.max_cuotas.vehicular'))->toBe(12)
        ->and($response->json('data.max_cuotas.prendario'))->toBe(1);
});

it('persists max_cuotas when an admin updates the configuracion', function () {
    Sanctum::actingAs($this->adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-credito-prendario', [
        'tipo_credito' => 'vehicular',
        'agencia_id' => $this->agencia->id,
        'interes_default' => 12,
        'plazo_dias' => 30,
        'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15,
        'tasa_mora_diaria' => 1,
        'max_cuotas' => 9,
    ])->assertSuccessful()->assertJsonPath('data.max_cuotas', 9);
});
