<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
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
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa, 'electro')->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->bien = Bien::factory()->forAgencia($this->agencia)->create(['tipo' => 'electro']);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

it('allows the registering asesor to view the crédito via show', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $this->bien->id, 'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/creditos-prendarios/{$creditoId}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $creditoId);
});

it('registers a crédito as pendiente with the config default interest', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $this->bien->id,
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.interes'))->toBe('10.00');
});

it('allows administrador_agencia to approve and generates contrato + declaracion', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $this->bien->id, 'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('aprobado');

    $tipos = CreditoPrendario::find($creditoId)
        ->documentos()->pluck('tipo')->sort()->values()->all();
    expect($tipos)->toBe(['contrato', 'declaracion']);
});

it('allows administrador_general to reject with a motivo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $this->bien->id, 'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $adminGeneral->assignRole('administrador_general');
    Sanctum::actingAs($adminGeneral, ['*']);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Documentación insuficiente'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'rechazado')
        ->assertJsonPath('data.motivo_rechazo', 'Documentación insuficiente');
});

it('denies asesor from approving their own crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $this->bien->id, 'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertForbidden();
});

it('activates the crédito once firmado, setting fecha_desembolso and fecha_vencimiento', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $this->bien->id, 'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/firmar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('activo')
        ->and($response->json('data.fecha_desembolso'))->not->toBeNull()
        ->and($response->json('data.fecha_vencimiento'))->not->toBeNull();

    $pendientesDeFirma = CreditoPrendario::find($creditoId)
        ->documentos()->whereNull('firmado_at')->count();
    expect($pendientesDeFirma)->toBe(0);
});

it('rejects firmar when the crédito is not yet aprobado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_id' => $this->bien->id, 'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/firmar")->assertUnprocessable();
});
