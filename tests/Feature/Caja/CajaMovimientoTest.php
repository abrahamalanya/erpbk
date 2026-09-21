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

it('accepts an optional descripcion and persists it', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoIngreso->id,
        'monto' => 100,
        'descripcion' => 'Vuelto de la venta del mediodía',
    ])->assertCreated()->assertJsonPath('data.descripcion', 'Vuelto de la venta del mediodía');
});

it('registers a movimiento fine without a descripcion', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoIngreso->id,
        'monto' => 100,
    ])->assertCreated()->assertJsonPath('data.descripcion', null);
});

it('exposes concepto as a plain string (not the eager-loaded Concepto relation object) on the cierre resumen', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/movimientos', [
        'tipo' => 'ingreso',
        'concepto_id' => $this->conceptoIngreso->id,
        'monto' => 100,
    ])->assertCreated();

    // CajaMovimiento has both a plain 'concepto' string column and a
    // concepto() belongsTo relation of the same name — if that relation is
    // ever eager-loaded again, Eloquent's serialization replaces the string
    // with the full Concepto model, and the frontend (which renders this
    // value directly as text) crashes the whole page. Regression for that.
    $response = $this->getJson('/api/caja/cierre/resumen')->assertSuccessful();
    expect($response->json('data.movimientos.0.concepto'))->toBe('Ingreso vario');
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

/** Registra un movimiento por API como $usuario (aperturando su caja si hace falta) y devuelve su id. */
function registrarMovimientoComo(User $usuario, array $datos): int
{
    Sanctum::actingAs($usuario, ['*']);
    test()->postJson('/api/caja/aperturar');

    return test()->postJson('/api/caja/movimientos', $datos)->assertCreated()->json('data.id');
}

it('shows each asesor only their own movimientos and an administrador de agencia all of their agencia', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');

    registrarMovimientoComo($this->asesor, ['tipo' => 'ingreso', 'concepto_id' => $this->conceptoIngreso->id, 'monto' => 10]);
    registrarMovimientoComo($otroAsesor, ['tipo' => 'ingreso', 'concepto_id' => $this->conceptoIngreso->id, 'monto' => 20]);
    registrarMovimientoComo($admin, ['tipo' => 'ingreso', 'concepto_id' => $this->conceptoIngreso->id, 'monto' => 30]);

    Sanctum::actingAs($this->asesor, ['*']);
    expect(collect($this->getJson('/api/caja/movimientos?tipo=ingreso')->json('data.data'))->pluck('monto')->all())->toBe(['10.00']);

    Sanctum::actingAs($admin, ['*']);
    expect(collect($this->getJson('/api/caja/movimientos?tipo=ingreso')->json('data.data'))->pluck('monto')->sort()->values()->all())
        ->toBe(['10.00', '20.00', '30.00']);
});

it('does not show an administrador de agencia the movimientos of another agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $ajeno = User::factory()->forAgencia($otraAgencia)->create();
    $ajeno->assignRole('asesor');
    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');

    registrarMovimientoComo($ajeno, ['tipo' => 'ingreso', 'concepto_id' => $this->conceptoIngreso->id, 'monto' => 99]);
    registrarMovimientoComo($this->asesor, ['tipo' => 'ingreso', 'concepto_id' => $this->conceptoIngreso->id, 'monto' => 10]);

    Sanctum::actingAs($admin, ['*']);
    expect(collect($this->getJson('/api/caja/movimientos?tipo=ingreso')->json('data.data'))->pluck('monto')->all())->toBe(['10.00']);
});

it('filters the movimientos by concepto, usuario, fecha and desembolsos', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    $otroGasto = Concepto::factory()->paraEmpresa($this->empresa)->create(['tipo' => 'gasto', 'nombre' => 'Combustible']);

    $comprobante = fn () => UploadedFile::fake()->image('comprobante.jpg');

    // Un egreso exige saldo en la caja: se fondea primero.
    foreach ([$this->asesor, $otroAsesor] as $usuario) {
        registrarMovimientoComo($usuario, ['tipo' => 'ingreso', 'concepto_id' => $this->conceptoIngreso->id, 'monto' => 1000]);
    }

    $idUtiles = registrarMovimientoComo($this->asesor, ['tipo' => 'egreso', 'concepto_id' => $this->conceptoGasto->id, 'monto' => 11, 'comprobante' => $comprobante()]);
    $idCombustible = registrarMovimientoComo($otroAsesor, ['tipo' => 'egreso', 'concepto_id' => $otroGasto->id, 'monto' => 22, 'comprobante' => $comprobante()]);
    CajaMovimiento::query()->findOrFail($idUtiles)->update(['fecha_caja' => now()->subDays(10)->toDateString()]);

    $desembolso = CajaMovimiento::query()->create([
        'caja_ciclo_id' => CajaMovimiento::query()->findOrFail($idCombustible)->caja_ciclo_id,
        'empresa_id' => $this->empresa->id, 'tipo' => 'egreso', 'monto' => 500, 'concepto' => 'Desembolso',
        'registrado_por' => $otroAsesor->id, 'fecha_caja' => now()->toDateString(),
    ]);

    Sanctum::actingAs($admin, ['*']);
    $montos = fn (string $query) => collect($this->getJson("/api/caja/movimientos?tipo=egreso{$query}")->assertSuccessful()->json('data.data'))->pluck('monto')->sort()->values()->all();

    expect($montos(''))->toBe(['11.00', '22.00', '500.00'])
        ->and($montos("&concepto_id={$this->conceptoGasto->id}"))->toBe(['11.00'])
        ->and($montos("&registrado_por={$otroAsesor->id}"))->toBe(['22.00', '500.00'])
        ->and($montos('&desde='.now()->subDays(2)->toDateString()))->toBe(['22.00', '500.00'])
        ->and($montos('&hasta='.now()->subDays(5)->toDateString()))->toBe(['11.00'])
        ->and($montos('&solo_desembolsos=1'))->toBe(['500.00'])
        ->and($desembolso->id)->not->toBeNull();
});

it('rejects inconsistent movimientos filters', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/caja/movimientos?tipo=ingreso&solo_desembolsos=1')->assertUnprocessable();
    $this->getJson("/api/caja/movimientos?tipo=egreso&solo_desembolsos=1&concepto_id={$this->conceptoGasto->id}")->assertUnprocessable();
    $this->getJson('/api/caja/movimientos?tipo=egreso&desde=2026-09-10&hasta=2026-09-01')->assertUnprocessable();
});

it('lists as filter options only the users whose movimientos the actor can see', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');

    foreach ([$this->asesor, $otroAsesor, $admin] as $usuario) {
        registrarMovimientoComo($usuario, ['tipo' => 'ingreso', 'concepto_id' => $this->conceptoIngreso->id, 'monto' => 5]);
    }

    Sanctum::actingAs($this->asesor, ['*']);
    expect(collect($this->getJson('/api/caja/movimientos/usuarios')->assertSuccessful()->json('data'))->pluck('id')->all())
        ->toBe([$this->asesor->id]);

    Sanctum::actingAs($admin, ['*']);
    expect(collect($this->getJson('/api/caja/movimientos/usuarios')->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->asesor->id, $otroAsesor->id, $admin->id])->sort()->values()->all());
});
