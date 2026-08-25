<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
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

it('approves a billetaje against a cuenta bancaria, crediting the caja saldo_actual but not saldo_efectivo', function () {
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
    // saldo_actual (total, spendable — e.g. can fund a desembolso) includes
    // the digital billetaje; the cierre screen's cash-only figure does not.
    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '150.00');

    $resumen = $this->getJson('/api/caja/cierre/resumen')->assertSuccessful();
    $resumen->assertJsonPath('data.saldo_calculado', '150.00');
    $resumen->assertJsonPath('data.saldo_efectivo', '0.00');
});

it('lets the asesor desembolsar a crédito prendario funded by a digital billetaje', function () {
    ConfiguracionCreditoPrendario::factory()
        ->deEmpresa($this->empresa)
        ->create(['interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1]);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create(['valorizacion' => 500]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 500,
        'motivo' => 'Fondos para desembolso',
        'medio_recepcion' => 'yape',
        'datos_recepcion' => '987654321',
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar", [
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'yape',
        'cuenta_bancaria_id' => $this->cuenta->id,
    ])->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '500.00');

    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id], 'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    foreach (CreditoPrendario::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    // Would fail with "no tienes saldo suficiente" if the digital billetaje
    // hadn't been credited to the caja's saldo_actual.
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'activo');
});

it('keeps the cierre diferencia at zero when monto_contado matches only the physical cash, ignoring a digital billetaje', function () {
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
    ])->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $response->assertJsonPath('data.saldo_calculado_cierre', '150.00')
        ->assertJsonPath('data.saldo_efectivo_cierre', '0.00')
        ->assertJsonPath('data.saldo_arqueo_cierre', '0.00')
        ->assertJsonPath('data.diferencia', '0.00');
});

it('floors saldo_efectivo at zero instead of going negative when egresos exceed the physical cash received', function () {
    Storage::fake('public');
    $concepto = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto']);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 2000,
        'motivo' => 'Fondos operativos',
        'medio_recepcion' => 'yape',
        'datos_recepcion' => '987654321',
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->cuenta->update(['saldo_inicial' => 2000]);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar", [
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'yape',
        'cuenta_bancaria_id' => $this->cuenta->id,
    ])->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    // No cash ingreso at all — the only funds are the 2000 received
    // digitally — yet a 500 gasto (always modeled as physical cash out)
    // is still allowed, since saldoActual() (total) covers it.
    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'egreso',
        'concepto_id' => $concepto->id,
        'monto' => 500,
        'comprobante' => UploadedFile::fake()->image('comprobante.jpg'),
    ])->assertCreated();

    $resumen = $this->getJson('/api/caja/cierre/resumen')->assertSuccessful();
    // Naively: 0 (cash ingresos) - 500 (egreso) = -500. Physically impossible.
    $resumen->assertJsonPath('data.saldo_efectivo', '0.00')
        ->assertJsonPath('data.saldo_calculado', '1500.00');
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
