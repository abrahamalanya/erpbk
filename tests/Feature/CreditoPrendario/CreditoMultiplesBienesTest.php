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

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCreditoPrendario::factory()->deEmpresa($this->empresa)->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    Sanctum::actingAs($this->asesor, ['*']);
});

it('creates a crédito backed by several bienes of the same cliente, monto_prestamo equal to the sum', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 200]);
    $bien2 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 300]);

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien1->id, $bien2->id],
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.cliente_id'))->toBe($this->cliente->id)
        ->and($response->json('data.bienes'))->toHaveCount(2);
});

it('rejects a monto_prestamo greater than the sum of the selected bienes valorizaciones', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 200]);
    $bien2 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 300]);

    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien1->id, $bien2->id],
        'monto_prestamo' => 501,
        'tipo_cuota' => 'mensual',
    ])->assertUnprocessable();
});

it('rejects bienes belonging to two different clientes in the same solicitud', function () {
    $otroCliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien1 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 200]);
    $bien2 = Bien::factory()->paraCliente($otroCliente)->create(['tipo' => 'electro', 'valorizacion' => 300]);

    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien1->id, $bien2->id],
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertUnprocessable();
});

it('allows bienes of different tipo (electro + varios) in the same solicitud', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 200]);
    $bien2 = Bien::factory()->paraCliente($this->cliente)->varios()->create(['valorizacion' => 300]);

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien1->id, $bien2->id],
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.bienes'))->toHaveCount(2);
});

it('rejects a bien already backing another active crédito, but allows it again once that crédito is liquidado', function () {
    $bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 500]);
    $activo = CreditoPrendario::factory()->paraBien($bien)->activo()->create(['registrado_por' => $this->asesor->id]);

    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 400,
        'tipo_cuota' => 'mensual',
    ])->assertUnprocessable();

    $activo->update(['estado' => 'liquidado']);
    $bien->update(['estado' => 'recuperado']);

    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 400,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();
});

it('carries the same set of bienes over to the new crédito on refrendo', function () {
    Storage::fake('public');

    $bien1 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 200]);
    $bien2 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 300]);

    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien1->id, $bien2->id],
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);

    foreach (CreditoPrendario::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    Caja::query()->where('user_id', $this->asesor->id)->first()->cicloAbierto->update(['saldo_apertura' => 10000]);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/refrendar", ['monto_interes_pagado' => 50])
        ->assertCreated();

    $bienesIds = collect($response->json('data.bienes'))->pluck('id')->sort()->values()->all();
    expect($bienesIds)->toBe([$bien1->id, $bien2->id]);
});

it('marks every bien of a crédito as recuperado when liquidated', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 200]);
    $bien2 = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 300]);
    $credito = CreditoPrendario::factory()->paraBienes(collect([$bien1, $bien2]))
        ->activo()
        ->create(['registrado_por' => $this->asesor->id]);

    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => 100000])->assertSuccessful();

    expect($bien1->fresh()->estado)->toBe('recuperado')
        ->and($bien2->fresh()->estado)->toBe('recuperado');
});
