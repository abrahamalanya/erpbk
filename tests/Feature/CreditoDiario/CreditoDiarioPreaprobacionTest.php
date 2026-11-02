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
use Laravel\Sanctum\Sanctum;

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

    $this->adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->adminGeneral->assignRole('administrador_general');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

function registrarCreditoDiarioPendiente(mixed $test): int
{
    Sanctum::actingAs($test->asesor, ['*']);

    return $test->postJson('/api/creditos-diarios', [
        'cliente_id' => $test->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
    ])->assertCreated()->json('data.id');
}

it('lets administrador_agencia preaprobar a diario pendiente, leaving it in preaprobado', function () {
    $creditoId = registrarCreditoDiarioPendiente($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/preaprobar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('preaprobado')
        ->and($response->json('data.preaprobado_por'))->toBe($this->adminAgencia->id)
        ->and($response->json('data.fecha_preaprobacion'))->not->toBeNull();
});

it('denies administrador_agencia from giving the final aprobación of a diario, even once preaprobado', function () {
    $creditoId = registrarCreditoDiarioPendiente($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/preaprobar")->assertSuccessful();
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertForbidden();

    expect(Credito::find($creditoId)->estado)->toBe('preaprobado');
});

it('lets administrador_general give the final aprobación only after the diario is preaprobado', function () {
    $creditoId = registrarCreditoDiarioPendiente($this);

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")
        ->assertUnprocessable();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/preaprobar")->assertSuccessful();

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('aprobado')
        ->and($response->json('data.aprobado_por'))->toBe($this->adminGeneral->id);
});

it('lets administrador_general preaprobar directly, acting in place of administrador_agencia, and then aprobar', function () {
    $creditoId = registrarCreditoDiarioPendiente($this);

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/preaprobar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'preaprobado')
        ->assertJsonPath('data.preaprobado_por', $this->adminGeneral->id);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'aprobado');
});

it('allows rechazar at either stage: pendiente (before preaprobar) or preaprobado (before the final aprobación)', function () {
    $pendienteId = registrarCreditoDiarioPendiente($this);
    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$pendienteId}/rechazar", ['motivo' => 'Datos incompletos'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'rechazado');

    $preaprobadoId = registrarCreditoDiarioPendiente($this);
    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$preaprobadoId}/preaprobar")->assertSuccessful();

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$preaprobadoId}/rechazar", ['motivo' => 'No cumple perfil'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'rechazado');
});

it('clears preaprobado_por when subsanar sends a rechazado diario back to pendiente', function () {
    $creditoId = registrarCreditoDiarioPendiente($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/preaprobar")->assertSuccessful();

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Revisar garantía'])->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/subsanar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.preaprobado_por'))->toBeNull()
        ->and($response->json('data.fecha_preaprobacion'))->toBeNull();
});

it('reverts a diario aprobación back to preaprobado (not pendiente), keeping preaprobado_por intact', function () {
    $creditoId = registrarCreditoDiarioPendiente($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/preaprobar")->assertSuccessful();

    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-aprobacion")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('preaprobado')
        ->and($response->json('data.preaprobado_por'))->toBe($this->adminAgencia->id)
        ->and($response->json('data.aprobado_por'))->toBeNull();
});

it('reverts a diario preaprobación back to pendiente, clearing preaprobado_por', function () {
    $creditoId = registrarCreditoDiarioPendiente($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/preaprobar")->assertSuccessful();

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-preaprobacion")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.preaprobado_por'))->toBeNull()
        ->and($response->json('data.fecha_preaprobacion'))->toBeNull();
});

it('denies preaprobar/revertir-preaprobacion on a non-diario crédito: it keeps the single-step flow', function () {
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);
    $bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/preaprobar")->assertForbidden();

    // administrador_agencia sigue pudiendo aprobar directo, sin preaprobación.
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'aprobado');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-preaprobacion")->assertForbidden();
});
