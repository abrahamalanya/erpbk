<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Simulador\Models\SimulacionCredito;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'prendario', 'interes_default' => 10, 'plazo_dias' => 30, 'max_cuotas' => 1,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'vehicular', 'interes_default' => 8, 'plazo_dias' => 90, 'max_cuotas' => 12,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario', 'interes_default' => 20, 'plazo_dias' => 30, 'max_cuotas' => 30,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

it('registra una simulación de crédito con el cronograma calculado, sin usar los defaults cuando el asesor los sobrescribe', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'prendario',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 400,
        'interes' => 15,
        'tipo_cuota' => 'semanal',
    ])->assertCreated();

    expect($response->json('data.cronograma'))->toHaveCount(4)
        ->and($response->json('data.plazo_dias'))->toBe(28)
        ->and((float) $response->json('data.interes'))->toBe(15.0)
        ->and($response->json('data.cliente.id'))->toBe($this->cliente->id);

    expect(SimulacionCredito::count())->toBe(1);
});

it('usa el interés y numero de cuotas por defecto de la configuración cuando no se envían', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'vehicular',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 1000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect((float) $response->json('data.interes'))->toBe(8.0);
});

it('registra una simulación de crédito diario', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'diario',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 300,
        'tipo_cuota' => 'diario',
    ])->assertCreated();

    expect($response->json('data.cronograma'))->toHaveCount(30)
        ->and((float) $response->json('data.interes'))->toBe(20.0);
});

it('rejects a numero_cuotas above the configured max_cuotas for the tipo_credito', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'vehicular',
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 1000,
        'tipo_cuota' => 'mensual',
        'numero_cuotas' => 24,
    ])->assertUnprocessable()->assertJsonValidationErrors('numero_cuotas');
});

it('rejects a cliente that belongs to a different agencia within the same empresa', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $clienteDeOtraAgencia = Cliente::factory()->forAgencia($otraAgencia)->create();

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/simulaciones-credito', [
        'tipo_credito' => 'prendario',
        'cliente_id' => $clienteDeOtraAgencia->id,
        'monto_prestamo' => 400,
        'tipo_cuota' => 'mensual',
    ])->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
});

it('lists only simulaciones visible to the actor and lets its author delete it', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    $propia = SimulacionCredito::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id, 'registrado_por' => $this->asesor->id,
    ]);
    SimulacionCredito::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id, 'registrado_por' => $otroAsesor->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/simulaciones-credito')->assertSuccessful()->assertJsonCount(1, 'data.data');

    $this->deleteJson("/api/simulaciones-credito/{$propia->id}")->assertSuccessful();
    expect(SimulacionCredito::count())->toBe(1);
});

it('prevents an asesor from deleting a simulación registered by another asesor', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    $ajena = SimulacionCredito::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id, 'registrado_por' => $otroAsesor->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->deleteJson("/api/simulaciones-credito/{$ajena->id}")->assertForbidden();
});
