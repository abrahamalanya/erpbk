<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Models\Banco;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->banco = Banco::factory()->create();

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $this->bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();

    $this->cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create([
        'banco_id' => $this->banco->id,
        'saldo_inicial' => 500,
        'acepta_yape' => true,
        'numero_yape' => '999888777',
    ]);

    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 1000])->assertCreated();

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
});

it('requires a vaucher to enviar a billetaje por cuenta_bancaria', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);

    $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'yape',
        'cuenta_bancaria_id' => $this->cuenta->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('comprobante');
});

it('credits the caja saldo_actual but not saldo_efectivo when accepted por cuenta bancaria', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 150,
        'motivo' => 'Vuelto para clientes',
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'yape',
        'cuenta_bancaria_id' => $this->cuenta->id,
        'comprobante' => UploadedFile::fake()->image('vaucher.jpg'),
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")
        ->assertSuccessful()->assertJsonPath('data.estado', 'aprobado');

    expect($this->cuenta->fresh()->saldoActual())->toBe('350.00');

    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '150.00');

    $resumen = $this->getJson('/api/caja/cierre/resumen')->assertSuccessful();
    $resumen->assertJsonPath('data.saldo_calculado', '150.00');
    $resumen->assertJsonPath('data.saldo_efectivo', '0.00');
});

it('denies sending with canal yape on a cuenta not affiliated to yape', function () {
    Storage::fake('public');
    $this->cuenta->update(['acepta_yape' => false, 'numero_yape' => null]);

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'yape',
        'cuenta_bancaria_id' => $this->cuenta->id,
        'comprobante' => UploadedFile::fake()->image('vaucher.jpg'),
    ])->assertUnprocessable()->assertJsonPath('message', 'La cuenta bancaria seleccionada no está afiliada a Yape.');
});

it('denies accepting a billetaje from a cuenta bancaria without saldo suficiente', function () {
    Storage::fake('public');
    $this->cuenta->update(['saldo_inicial' => 50]);

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'yape',
        'cuenta_bancaria_id' => $this->cuenta->id,
        'comprobante' => UploadedFile::fake()->image('vaucher.jpg'),
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La cuenta bancaria no tiene saldo suficiente para entregar este billetaje.');
});

it('still defaults to efectivo when the body omits medio_egreso', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")->assertSuccessful()->assertJsonPath('data.estado', 'aprobado');

    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '100.00');
});
