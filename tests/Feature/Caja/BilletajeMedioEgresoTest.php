<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Models\Banco;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->banco = Banco::factory()->create();

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->bovedaAgencia = Boveda::find($bovedaAgenciaId);

    $this->cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create([
        'banco_id' => $this->banco->id,
        'saldo_inicial' => 500,
        'acepta_yape' => true,
        'numero_yape' => '999888777',
    ]);

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
});

it('rejects a billetaje solicitud without a motivo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/billetajes', ['monto' => 100, 'medio_recepcion' => 'efectivo'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motivo');
});

it('requires datos_recepcion when medio_recepcion is not efectivo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/billetajes', ['monto' => 100, 'motivo' => 'Vuelto para clientes', 'medio_recepcion' => 'yape'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('datos_recepcion');
});

it('approves a billetaje against a cuenta bancaria without creating a caja movimiento', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 150,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'yape',
        'datos_recepcion' => '987654321',
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar", [
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'yape',
        'cuenta_bancaria_id' => $this->cuenta->id,
    ])->assertSuccessful()->assertJsonPath('data.estado', 'aprobado');

    expect($this->cuenta->fresh()->saldoActual())->toBe('350.00');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '0.00');
});

it('denies approving with canal yape on a cuenta not affiliated to yape', function () {
    $this->cuenta->update(['acepta_yape' => false, 'numero_yape' => null]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'yape',
        'datos_recepcion' => '987654321',
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar", [
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'yape',
        'cuenta_bancaria_id' => $this->cuenta->id,
    ])->assertUnprocessable()->assertJsonPath('message', 'La cuenta bancaria seleccionada no está afiliada a Yape.');
});

it('still approves in efectivo when the request body is empty, preserving the historical default', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 1000])->assertCreated();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'efectivo',
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")->assertSuccessful()->assertJsonPath('data.estado', 'aprobado');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '100.00');
});
