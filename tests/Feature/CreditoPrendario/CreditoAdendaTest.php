<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

function abreCaja(Empresa $empresa, Agencia $agencia, User $user): void
{
    $caja = Caja::factory()->create(['user_id' => $user->id, 'empresa_id' => $empresa->id, 'agencia_id' => $agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1, 'max_refrendos' => null,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro']);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');

    abreCaja($this->empresa, $this->agencia, $this->asesor);
    abreCaja($this->empresa, $this->agencia, $this->adminAgencia);
});

function firmarDocumentos(TestCase $test, User $asesor, int $creditoId): void
{
    Sanctum::actingAs($asesor, ['*']);
    foreach (CreditoPrendario::find($creditoId)->documentos as $documento) {
        $test->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }
}

it('lets an admin create a pendiente successor with new condiciones, closing the original as adendado', function () {
    Storage::fake('public');

    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20, 'tipo_cuota' => 'mensual']);

    Sanctum::actingAs($this->adminAgencia, ['*']);

    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');
    $response = $this->postJson("/api/creditos-prendarios/{$original->id}/adendar", [
        'monto_pagado' => $sugerido,
        'interes' => 25,
        'tipo_cuota' => 'quincenal',
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.adenda_de_credito_id'))->toBe($original->id)
        ->and($response->json('data.interes'))->toBe('25.00')
        ->and($response->json('data.tipo_cuota'))->toBe('quincenal')
        ->and($response->json('data.monto_prestamo'))->toBe('1000.00')
        ->and($response->json('data.bienes.0.id'))->toBe($this->bien->id);

    expect($original->fresh()->estado)->toBe('adendado');

    $nuevoId = $response->json('data.id');
    $tipos = CreditoPrendario::find($nuevoId)->documentos()->pluck('tipo')->all();
    expect($tipos)->toEqualCanonicalizing(['contrato', 'declaracion', 'fotos']);
});

it('lets an asesor adendar collecting only the interest, keeping the current tasa/tipo_cuota on the successor', function () {
    Storage::fake('public');

    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20, 'tipo_cuota' => 'mensual']);

    Sanctum::actingAs($this->asesor, ['*']);

    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');
    $response = $this->postJson("/api/creditos-prendarios/{$original->id}/adendar", [
        'monto_pagado' => $sugerido,
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.interes'))->toBe('20.00')
        ->and($response->json('data.tipo_cuota'))->toBe('mensual');
});

it('denies an asesor from setting a nueva tasa/tipo_cuota at adendar time (needs creditos_prendarios.editar)', function () {
    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->asesor, ['*']);

    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$original->id}/adendar", [
        'monto_pagado' => $sugerido, 'interes' => 25, 'medio' => 'efectivo',
    ])->assertForbidden();
});

it('registers the cobro as an ingreso in the actor\'s own caja, with medio', function () {
    Storage::fake('public');

    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->asesor, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido');

    $this->postJson("/api/creditos-prendarios/{$original->id}/adendar", [
        'monto_pagado' => $sugerido['total'], 'medio' => 'yape',
        'comprobante' => UploadedFile::fake()->image('yape.jpg'),
    ])->assertCreated();

    $ciclo = Caja::query()->where('user_id', $this->asesor->id)->first()->cicloAbierto()->first();
    $movimiento = $ciclo->movimientos()->where('tipo', 'ingreso')->first();

    expect($movimiento)->not->toBeNull()
        ->and($movimiento->medio)->toBe('yape')
        ->and((string) $movimiento->monto)->toBe($sugerido['total'])
        ->and($movimiento->fotos()->where('tipo', 'comprobante')->exists())->toBeTrue();
});

it('rejects a non-efectivo medio without a comprobante', function () {
    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->asesor, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');

    $this->postJson("/api/creditos-prendarios/{$original->id}/adendar", [
        'monto_pagado' => $sugerido, 'medio' => 'yape',
    ])->assertUnprocessable()->assertJsonValidationErrors('comprobante');
});

it('requires the actor to have an open caja before adendar', function () {
    $sinCaja = User::factory()->forAgencia($this->agencia)->create();
    $sinCaja->assignRole('asesor');

    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $sinCaja->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($sinCaja, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');

    $this->postJson("/api/creditos-prendarios/{$original->id}/adendar", [
        'monto_pagado' => $sugerido, 'medio' => 'efectivo',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'Debes aperturar tu caja antes de registrar un cobro.');
});

it('runs the successor through aprobar/firmar/desembolsar without touching caja at all', function () {
    Storage::fake('public');

    $original = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20, 'tipo_cuota' => 'mensual']);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$original->id}")->json('data.monto_refrendo_sugerido.total');
    $nuevoId = $this->postJson("/api/creditos-prendarios/{$original->id}/adendar", [
        'monto_pagado' => $sugerido, 'interes' => 25, 'medio' => 'efectivo',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$nuevoId}/aprobar")->assertSuccessful();

    firmarDocumentos($this, $this->asesor, $nuevoId);

    // El desembolso del sucesor de una adenda no toca caja en absoluto — el
    // pago del interés ya se registró arriba (en adendar()), esto solo
    // activa el crédito con las condiciones nuevas.
    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$nuevoId}/desembolsar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('activo');
    expect(CreditoPrendario::find($nuevoId)->cuotas)->toHaveCount(1);
});

it('rejects adendar with a monto_pagado below the calculated interest', function () {
    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->adminAgencia, ['*']);

    $this->postJson("/api/creditos-prendarios/{$activo->id}/adendar", ['monto_pagado' => 50, 'interes' => 25, 'medio' => 'efectivo'])
        ->assertUnprocessable();
});

it('rejects adendar when monto_pagado covers the full total, suggesting Liquidar instead', function () {
    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->adminAgencia, ['*']);

    $this->postJson("/api/creditos-prendarios/{$activo->id}/adendar", ['monto_pagado' => 1100, 'interes' => 25, 'medio' => 'efectivo'])
        ->assertUnprocessable();
});

it('abona a capital cuando el monto pagado supera el interés, same as refrendar', function () {
    // interés = 1000 x 20% x 15 / 3000 = 100.00; paga 300 -> abono 200 -> sucesor con 800.
    $activo = CreditoPrendario::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'monto_prestamo' => 1000, 'interes' => 20]);

    Sanctum::actingAs($this->adminAgencia, ['*']);

    $response = $this->postJson("/api/creditos-prendarios/{$activo->id}/adendar", [
        'monto_pagado' => 300, 'interes' => 25, 'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.monto_prestamo'))->toBe('800.00');
});

it('rejects adendar on a crédito still pendiente', function () {
    $pendiente = CreditoPrendario::factory()->paraBien($this->bien)
        ->create(['registrado_por' => $this->asesor->id, 'estado' => 'pendiente']);

    Sanctum::actingAs($this->adminAgencia, ['*']);

    $this->postJson("/api/creditos-prendarios/{$pendiente->id}/adendar", ['monto_pagado' => 50, 'interes' => 25, 'medio' => 'efectivo'])
        ->assertUnprocessable();
});
