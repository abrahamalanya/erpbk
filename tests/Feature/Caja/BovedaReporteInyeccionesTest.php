<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\BovedaMovimiento;
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

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->getJson('/api/bovedas')->assertSuccessful();
    $this->bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();
    $this->bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/aperturar", ['saldo_inicial' => 5000])->assertCreated();

    $this->banco = Banco::factory()->create();
});

it('lists both cash and cuenta bancaria inyecciones in one report', function () {
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", [
        'monto' => 300,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuenta->id,
    ])->assertCreated();

    $response = $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones")->assertSuccessful();

    expect($response->json('data'))->toHaveCount(2)
        ->and(collect($response->json('data'))->pluck('medio')->sort()->values()->all())
        ->toBe(['cuenta_bancaria', 'efectivo']);
});

it('filters the report by fecha desde/hasta', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->assertCreated();

    $hoy = now()->toDateString();
    $manana = now()->addDay()->toDateString();

    $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones?desde={$manana}")
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones?desde={$hoy}&hasta={$hoy}")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data');
});

it('marks a cash inyeccion as eliminable only while it belongs to the currently open ciclo', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->assertCreated();

    $response = $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones")->assertSuccessful();
    expect($response->json('data.0.puede_eliminar'))->toBeTrue();

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/cerrar", ['monto_contado' => 5200])->assertSuccessful();
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/aperturar")->assertCreated();

    $response = $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones")->assertSuccessful();
    expect($response->json('data.0.puede_eliminar'))->toBeFalse();
});

it('marks a cuenta bancaria inyeccion as never eliminable', function () {
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", [
        'monto' => 300,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuenta->id,
    ])->assertCreated();

    $response = $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones")->assertSuccessful();
    expect($response->json('data.0.puede_eliminar'))->toBeFalse();
});

it('deletes a standalone cash inyeccion within the open ciclo', function () {
    $movimientoId = $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->json('data.id');

    $this->deleteJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones/{$movimientoId}")->assertSuccessful();

    expect(BovedaMovimiento::query()->find($movimientoId))->toBeNull();
    $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}")->assertJsonPath('data.ciclo_abierto.saldo_actual', '5000.00');
});

it('refuses to delete a cash inyeccion that no longer belongs to the open ciclo', function () {
    $movimientoId = $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->json('data.id');

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/cerrar", ['monto_contado' => 5200])->assertSuccessful();
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/aperturar")->assertCreated();

    $this->deleteJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones/{$movimientoId}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Este movimiento no se puede eliminar: no pertenece al ciclo actual de esta bóveda.');
});

it('deletes both sides of a cash traspaso together when both ciclos are still open', function () {
    $ingresoId = $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 700])->json('data.id');
    $egresoId = BovedaMovimiento::query()->where('origen', 'traspaso')->where('tipo', 'egreso')->firstOrFail()->id;

    $this->deleteJson("/api/bovedas/{$this->bovedaAgencia->id}/inyecciones/{$ingresoId}")->assertSuccessful();

    expect(BovedaMovimiento::query()->find($ingresoId))->toBeNull()
        ->and(BovedaMovimiento::query()->find($egresoId))->toBeNull();

    $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}")->assertJsonPath('data.ciclo_abierto.saldo_actual', '5000.00');
});

it('shows a bank-to-bank traspaso as not eliminable on either side (no ciclo concept applies to a cuenta bancaria)', function () {
    $cuentaPrincipal = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id, 'saldo_inicial' => 1000]);
    $cuentaAgencia = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create(['banco_id' => $this->banco->id]);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", [
        'monto' => 400,
        'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuentaAgencia->id,
        'cuenta_bancaria_origen_id' => $cuentaPrincipal->id,
    ])->assertCreated();

    // Neither side is a BovedaMovimiento — the whole transfer stayed within the bank accounts.
    expect(BovedaMovimiento::query()->where('origen', 'traspaso')->exists())->toBeFalse();

    $reportePrincipal = $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones")->json('data');
    expect($reportePrincipal)->not->toBeEmpty();
    foreach ($reportePrincipal as $item) {
        expect($item['puede_eliminar'])->toBeFalse();
    }
});

it('refuses to delete a traspaso when the other side already left its own open ciclo', function () {
    $ingresoId = $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 700])->json('data.id');

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');

    // The principal can't close while the agencia bóveda is open, so close
    // the agencia first (only administrador_agencia controls it), then
    // reabrir it (same ciclo restored — the ingreso stays eligible) while
    // the principal gets a genuinely NEW ciclo via aperturar() (the
    // egreso's original ciclo is now stuck behind).
    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/cerrar", ['monto_contado' => 700])->assertSuccessful();

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/cerrar", ['monto_contado' => 4300])->assertSuccessful();
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/aperturar")->assertCreated();

    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/reabrir")->assertSuccessful();

    // Only administrador_general holds bovedas.inyectar (the authority the
    // report/delete endpoints reuse), even for the agencia's own report.
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->deleteJson("/api/bovedas/{$this->bovedaAgencia->id}/inyecciones/{$ingresoId}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No se puede eliminar: el otro lado de este traspaso ya no pertenece al ciclo actual de su bóveda.');

    expect(BovedaMovimiento::query()->find($ingresoId))->not->toBeNull();
});

it('denies administrador_agencia from deleting inyecciones (not the inyectar authority)', function () {
    $movimientoId = $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyectar", ['monto' => 200])->json('data.id');

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->deleteJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones/{$movimientoId}")->assertForbidden();
});
