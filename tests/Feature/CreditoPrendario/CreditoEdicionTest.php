<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoHipotecario\Models\Inmueble;
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
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

it('allows administrador_agencia to revert an accidental aprobación back to pendiente', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-aprobacion")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'pendiente');

    $credito = Credito::find($creditoId);
    expect($credito->aprobado_por)->toBeNull()
        ->and($credito->fecha_aprobacion)->toBeNull();
});

it('allows a different admin with authority (not just who approved) to revert', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    $otroAdminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $otroAdminAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($otroAdminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-aprobacion")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'pendiente');
});

it('denies revertir-aprobacion on a crédito that is not aprobado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-aprobacion")->assertUnprocessable();
});

it('denies asesor from revertir-aprobacion', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-aprobacion")->assertForbidden();
});

it('allows administrador_agencia to update the interest rate while pendiente or aprobado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-interes", ['interes' => 5])
        ->assertSuccessful()
        ->assertJsonPath('data.interes', '5.00');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-interes", ['interes' => 3.5])
        ->assertSuccessful()
        ->assertJsonPath('data.interes', '3.50');
});

it('denies updating the interest rate once the crédito is activo', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);

    foreach (Credito::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    Caja::query()->where('user_id', $this->asesor->id)->first()->cicloAbierto->update(['saldo_apertura' => 10000]);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-interes", ['interes' => 5])
        ->assertUnprocessable();
});

it('denies asesor from updating the interest rate', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-interes", ['interes' => 5])
        ->assertForbidden();
});

it('denies asesor from setting a custom interes when registering a crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'interes' => 3, 'tipo_cuota' => 'mensual',
    ])->assertForbidden();

    // Sin el override, el asesor sí puede registrar (usa el default de config).
    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.interes'))->toBe('10.00');
});

it('exposes the resolved interés por defecto to a user who can register créditos', function () {
    ConfiguracionCredito::factory()->deAgencia($this->agencia)->create([
        'tipo_credito' => 'prendario', 'interes_default' => 7,
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/creditos-prendarios/configuracion')
        ->assertOk()
        ->assertJsonPath('data.interes_default.prendario', '7.00')
        ->assertJsonPath('data.interes_default.vehicular', null);
});

it('still denies asesor from overriding the interes without flagging it as a solicitud especial', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'interes' => 3, 'motivo_interes' => 'Cliente exclusivo',
        'interes_solicitud_especial' => false, 'tipo_cuota' => 'mensual',
    ])->assertForbidden();
});

it('lets an asesor override the interes when it is flagged as a solicitud especial with a motivo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'interes' => 3, 'tipo_cuota' => 'mensual',
        'interes_solicitud_especial' => true,
        'motivo_interes' => 'Cliente exclusivo con historial impecable',
    ])->assertCreated();

    expect($response->json('data.interes'))->toBe('3.00')
        ->and($response->json('data.interes_solicitud_especial'))->toBeTrue()
        ->and($response->json('data.motivo_interes'))->toBe('Cliente exclusivo con historial impecable');
});

it('requires a motivo when a solicitud especial interes differs from the configured default', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'interes' => 3, 'tipo_cuota' => 'mensual',
        'interes_solicitud_especial' => true,
    ])->assertUnprocessable();
});

it('does not require a motivo when the flagged interes equals the configured default', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'interes' => 10, 'tipo_cuota' => 'mensual',
        'interes_solicitud_especial' => true,
    ])->assertCreated();

    expect($response->json('data.interes'))->toBe('10.00')
        ->and($response->json('data.motivo_interes'))->toBeNull();
});

it('exposes the motivo del interés and the solicitud especial flag in the crédito detail', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'interes' => 4, 'tipo_cuota' => 'mensual',
        'interes_solicitud_especial' => true,
        'motivo_interes' => 'Tasa preferente aprobada por gerencia',
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/creditos-prendarios/{$creditoId}")
        ->assertOk()
        ->assertJsonPath('data.interes', '4.00')
        ->assertJsonPath('data.interes_solicitud_especial', true)
        ->assertJsonPath('data.motivo_interes', 'Tasa preferente aprobada por gerencia');
});

it('allows administrador_agencia to set a custom interes when registering a crédito', function () {
    $cajaAdmin = Caja::factory()->create(['user_id' => $this->adminAgencia->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $cajaAdmin->id, 'empresa_id' => $cajaAdmin->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'interes' => 3, 'tipo_cuota' => 'mensual',
        'motivo_interes' => 'Tasa negociada con el cliente',
    ])->assertCreated();

    expect($response->json('data.interes'))->toBe('3.00')
        ->and($response->json('data.motivo_interes'))->toBe('Tasa negociada con el cliente');
});

it('streams a freshly rendered PDF for a generated documento, without persisting a pdf_path', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    $documento = Credito::find($creditoId)->documentos()->where('tipo', 'contrato')->firstOrFail();
    expect($documento->getAttributes())->not->toHaveKey('pdf_path');

    $response = $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/ver");

    $response->assertSuccessful();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('denies the asesor from viewing documentos while the crédito is pendiente or rechazado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $documento = Credito::find($creditoId)->documentos()->where('tipo', 'contrato')->firstOrFail();

    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/ver")
        ->assertForbidden();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'falta información'])
        ->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/ver")
        ->assertForbidden();
});

it('allows the asesor to view documentos once aprobado, and revokes it again if the aprobación is reverted', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $documento = Credito::find($creditoId)->documentos()->where('tipo', 'contrato')->firstOrFail();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/ver")
        ->assertSuccessful();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-aprobacion")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/ver")
        ->assertForbidden();
});

it('allows administrador_agencia to view documentos while the crédito is still pendiente', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $documento = Credito::find($creditoId)->documentos()->where('tipo', 'contrato')->firstOrFail();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/ver")
        ->assertSuccessful();
});

it('returns 404 when the documento does not belong to the given crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $bienOtroCredito = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $otroCreditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bienOtroCredito->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$otroCreditoId}/aprobar")->assertSuccessful();

    $documentoDeOtroCredito = Credito::find($otroCreditoId)->documentos()->firstOrFail();

    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$documentoDeOtroCredito->id}/ver")
        ->assertNotFound();
});

it('allows administrador_agencia to update tipo_cuota and monto_prestamo while pendiente', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", [
        'tipo_cuota' => 'quincenal',
        'monto_prestamo' => 800,
    ])->assertSuccessful()
        ->assertJsonPath('data.tipo_cuota', 'quincenal')
        ->assertJsonPath('data.monto_prestamo', '800.00');
});

it('denies a monto_prestamo above the sum of the garantías valorizaciones', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", ['monto_prestamo' => 1500])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El monto del préstamo no puede superar la suma de las valorizaciones de las garantías seleccionadas.');
});

it('denies switching a prendario to interés compuesto', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", ['tipo_interes' => 'compuesto'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Solo los créditos hipotecarios pueden ser de interés compuesto.');
});

it('denies editing condiciones once the crédito has a cronograma (ya desembolsado)', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    Caja::query()->where('user_id', $this->asesor->id)->first()->cicloAbierto->update(['saldo_apertura' => 10000]);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", ['tipo_cuota' => 'quincenal'])
        ->assertUnprocessable();
});

it('denies asesor from updating condiciones', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", ['tipo_cuota' => 'quincenal'])
        ->assertForbidden();
});

it('requires at least one condición to update', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", [])
        ->assertUnprocessable();
});

it('allows administrador_general to switch a hipotecario crédito to interés compuesto once numero_cuotas is set', function () {
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'hipotecario', 'interes_default' => 8, 'plazo_dias' => 30,
        'dias_espera_mora' => 30, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_cuotas' => 24,
    ]);

    $inmueble = Inmueble::factory()->paraCliente($this->cliente)->create(['valorizacion' => 150000]);
    $adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $adminGeneral->assignRole('administrador_general');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($adminGeneral, ['*']);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", ['tipo_interes' => 'compuesto'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Debes indicar el número de cuotas antes de cambiar a interés compuesto.');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", [
        'tipo_interes' => 'compuesto',
        'monto_prestamo' => 80000,
    ])->assertUnprocessable();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-numero-cuotas", ['numero_cuotas' => 12])
        ->assertSuccessful();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-condiciones", [
        'tipo_interes' => 'compuesto',
        'monto_prestamo' => 80000,
    ])->assertSuccessful()
        ->assertJsonPath('data.tipo_interes', 'compuesto')
        ->assertJsonPath('data.monto_prestamo', '80000.00');
});
