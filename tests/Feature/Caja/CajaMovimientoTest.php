<?php

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('public');

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->conceptoIngreso = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'ingreso', 'nombre' => 'Ingreso vario']);
    $this->conceptoGasto = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto', 'nombre' => 'Útiles de oficina']);
});

it('registers an ingreso without a comprobante', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoIngreso->id,
        'monto' => 100,
    ])->assertCreated()->assertJsonPath('data.tipo', 'ingreso');

    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '100.00');
});

it('requires a comprobante to register a gasto', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoIngreso->id,
        'monto' => 200,
    ])->assertCreated();

    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'egreso',
        'concepto_id' => $this->conceptoGasto->id,
        'monto' => 50,
    ])->assertUnprocessable()->assertJsonValidationErrors('comprobante');
});

it('registers a gasto with comprobante and fotos adicionales, lowering the saldo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoIngreso->id,
        'monto' => 200,
    ])->assertCreated();

    $response = $this->postJson('/api/caja/movimientos', [
        'tipo' => 'egreso',
        'concepto_id' => $this->conceptoGasto->id,
        'monto' => 50,
        'comprobante' => UploadedFile::fake()->image('comprobante.jpg'),
        'fotos_adicionales' => [UploadedFile::fake()->image('extra1.jpg'), UploadedFile::fake()->image('extra2.jpg')],
    ])->assertCreated();

    $movimiento = CajaMovimiento::findOrFail($response->json('data.id'));
    expect($movimiento->fotos()->where('tipo', 'comprobante')->count())->toBe(1)
        ->and($movimiento->fotos()->where('tipo', 'adicional')->count())->toBe(2);

    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '150.00');
});

it('denies a gasto larger than the current saldo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'egreso',
        'concepto_id' => $this->conceptoGasto->id,
        'monto' => 50,
        'comprobante' => UploadedFile::fake()->image('comprobante.jpg'),
    ])->assertUnprocessable()->assertJsonPath('message', 'Tu caja no tiene saldo suficiente para este gasto.');
});

it('rejects a concepto whose tipo does not match the movimiento tipo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoGasto->id,
        'monto' => 50,
    ])->assertUnprocessable()->assertJsonPath('message', 'El concepto seleccionado no es válido para este tipo de movimiento.');
});

it('shows the resumen de cierre with the movimientos detail and saldo_calculado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoIngreso->id,
        'monto' => 300,
    ])->assertCreated();

    $this->getJson('/api/caja/cierre/resumen')
        ->assertSuccessful()
        ->assertJsonPath('data.saldo_calculado', '300.00')
        ->assertJsonCount(1, 'data.movimientos');
});

it('lists the actor own ingreso history across ciclos, separate from gastos', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoIngreso->id,
        'monto' => 100,
    ])->assertCreated();
    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'egreso',
        'concepto_id' => $this->conceptoGasto->id,
        'monto' => 40,
        'comprobante' => UploadedFile::fake()->image('comprobante.jpg'),
    ])->assertCreated();

    // Closing and reopening a new ciclo proves the listing isn't scoped to
    // the currently open ciclo, unlike resumenCierre.
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 60])->assertSuccessful();
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->getJson('/api/caja/movimientos?tipo=ingreso')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.monto', '100.00');

    $this->getJson('/api/caja/movimientos?tipo=egreso')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.data')
        ->assertJsonPath('data.data.0.monto', '40.00');
});

it('rejects listing movimientos without a valid tipo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->getJson('/api/caja/movimientos')->assertUnprocessable();
    $this->getJson('/api/caja/movimientos?tipo=billetaje')->assertUnprocessable();
});
