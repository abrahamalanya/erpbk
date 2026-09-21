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

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->getJson('/api/bovedas')->assertSuccessful();
    $this->bovedaPrincipal = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail();
    $this->bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/aperturar", ['saldo_inicial' => 5000])->assertCreated();

    $this->banco = Banco::factory()->create();
});

function saldoEfectivoDe(Boveda $boveda): string
{
    return test()->getJson("/api/bovedas/{$boveda->id}")->json('data.ciclo_abierto.saldo_actual');
}

it('withdraws cash from the principal boveda as an external egreso', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/retirar", ['monto' => 1200, 'concepto' => 'Retiro del dueño'])
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'egreso')
        ->assertJsonPath('data.origen', 'retiro')
        ->assertJsonPath('data.monto', '1200.00');

    expect(saldoEfectivoDe($this->bovedaPrincipal))->toBe('3800.00');
});

it('rejects a cash retiro bigger than the boveda saldo', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/retirar", ['monto' => 5000.01])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La bóveda no tiene saldo suficiente en efectivo para este retiro.');

    expect(saldoEfectivoDe($this->bovedaPrincipal))->toBe('5000.00');
});

it('rejects a retiro when the principal boveda has no open ciclo', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/cerrar", ['monto_contado' => 5000])->assertSuccessful();

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/retirar", ['monto' => 100])
        ->assertUnprocessable();
});

it('withdraws from a cuenta bancaria of the principal boveda, leaving its cash untouched', function () {
    $cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id, 'saldo_inicial' => 900]);

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/retirar", [
        'monto' => 400, 'medio' => 'cuenta_bancaria', 'cuenta_bancaria_id' => $cuenta->id,
    ])->assertCreated()->assertJsonPath('data.origen', 'retiro');

    expect($cuenta->fresh()->saldoActual())->toBe('500.00')
        ->and(saldoEfectivoDe($this->bovedaPrincipal))->toBe('5000.00');

    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/retirar", [
        'monto' => 600, 'medio' => 'cuenta_bancaria', 'cuenta_bancaria_id' => $cuenta->id,
    ])->assertUnprocessable()->assertJsonPath('message', 'La cuenta bancaria no tiene saldo suficiente para este movimiento.');
});

it('requires a cuenta_bancaria_id when the retiro medio is cuenta_bancaria', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/retirar", ['monto' => 100, 'medio' => 'cuenta_bancaria'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cuenta_bancaria_id');
});

it('returns cash from an agencia boveda to the principal as a linked pair', function () {
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 1000])->assertCreated();
    expect(saldoEfectivoDe($this->bovedaAgencia))->toBe('1000.00')
        ->and(saldoEfectivoDe($this->bovedaPrincipal))->toBe('4000.00');

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/retirar", ['monto' => 400])
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'egreso')
        ->assertJsonPath('data.origen', 'devolucion');

    expect(saldoEfectivoDe($this->bovedaAgencia))->toBe('600.00')
        ->and(saldoEfectivoDe($this->bovedaPrincipal))->toBe('4400.00');
});

it('rejects an agencia devolución bigger than the agencia saldo without touching the principal', function () {
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 300])->assertCreated();

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/retirar", ['monto' => 300.01])->assertUnprocessable();

    expect(saldoEfectivoDe($this->bovedaAgencia))->toBe('300.00')
        ->and(saldoEfectivoDe($this->bovedaPrincipal))->toBe('4700.00');
});

it('returns bank-to-bank from an agencia cuenta to a principal cuenta and requires the destino', function () {
    $cuentaPrincipal = CuentaBancaria::factory()->paraBoveda($this->bovedaPrincipal)->create(['banco_id' => $this->banco->id]);
    $cuentaAgencia = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create(['banco_id' => $this->banco->id, 'saldo_inicial' => 700]);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/retirar", [
        'monto' => 200, 'medio' => 'cuenta_bancaria', 'cuenta_bancaria_id' => $cuentaAgencia->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('cuenta_bancaria_destino_id');

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/retirar", [
        'monto' => 200, 'medio' => 'cuenta_bancaria',
        'cuenta_bancaria_id' => $cuentaAgencia->id, 'cuenta_bancaria_destino_id' => $cuentaPrincipal->id,
    ])->assertCreated()->assertJsonPath('data.origen', 'devolucion');

    expect($cuentaAgencia->fresh()->saldoActual())->toBe('500.00')
        ->and($cuentaPrincipal->fresh()->saldoActual())->toBe('200.00');
});

it('only lets the administrador_general retirar', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/retirar", ['monto' => 10])->assertForbidden();
});

it('lists a retiro in the inyecciones report and undoes it while the ciclo is open', function () {
    $this->postJson("/api/bovedas/{$this->bovedaPrincipal->id}/retirar", ['monto' => 700])->assertCreated();

    $reporte = $this->getJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones")->assertSuccessful()->json('data');
    $retiro = collect($reporte)->firstWhere('origen', 'retiro');

    expect($retiro)->not->toBeNull()
        ->and($retiro['tipo'])->toBe('egreso')
        ->and($retiro['puede_eliminar'])->toBeTrue();

    $this->deleteJson("/api/bovedas/{$this->bovedaPrincipal->id}/inyecciones/{$retiro['id']}")->assertSuccessful();

    expect(saldoEfectivoDe($this->bovedaPrincipal))->toBe('5000.00');
});

it('undoing an agencia devolución removes both sides of the pair', function () {
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 1000])->assertCreated();
    $devolucionId = $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/retirar", ['monto' => 400])->assertCreated()->json('data.id');

    $this->deleteJson("/api/bovedas/{$this->bovedaAgencia->id}/inyecciones/{$devolucionId}")->assertSuccessful();

    expect(saldoEfectivoDe($this->bovedaAgencia))->toBe('1000.00')
        ->and(saldoEfectivoDe($this->bovedaPrincipal))->toBe('4000.00');
});
