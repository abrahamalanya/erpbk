<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('public');
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

/**
 * Registers, aprueba, firma documentos y desembolsa un crédito semanal
 * (4 cuotas por defecto, 7 días cada una) para tener varias cuotas que
 * revisar; retorna su id. tipo_cuota semanal en vez de pasar numero_cuotas
 * a propósito: pasarlo exige el permiso creditos_prendarios.editar además
 * de desembolsar, que el asesor no tiene.
 */
function desembolsarCreditoDeTres($test): int
{
    Sanctum::actingAs($test->asesor, ['*']);
    $creditoId = $test->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$test->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($test->adminAgencia, ['*']);
    $test->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($test->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $test->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    $test->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    return $creditoId;
}

it('lets an admin correct the fecha de desembolso of an activo crédito and shifts the cronograma', function () {
    $creditoId = desembolsarCreditoDeTres($this);
    $montosOriginales = Credito::find($creditoId)->cuotas->pluck('monto_total', 'numero_cuota');

    $nuevaFecha = now()->subDays(45)->toDateString();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => $nuevaFecha,
    ])->assertSuccessful();

    $credito = Credito::find($creditoId)->load('cuotas');

    expect($credito->fecha_desembolso->toDateString())->toBe($nuevaFecha)
        ->and($credito->fecha_vencimiento->toDateString())->toBe(now()->subDays(45)->addDays(28)->toDateString());

    foreach ($credito->cuotas as $cuota) {
        // Cada cuota semanal queda a 7 días de la nueva fecha de desembolso, por número de cuota.
        expect($cuota->fecha_vencimiento->toDateString())
            ->toBe(now()->subDays(45)->addDays(7 * $cuota->numero_cuota)->toDateString());
    }

    // Los montos de las cuotas no cambian, solo las fechas.
    expect($credito->cuotas->pluck('monto_total', 'numero_cuota')->all())->toBe($montosOriginales->all());
});

it('denies editing the fecha de desembolso while pendiente or aprobado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->subDays(10)->toDateString(),
    ])->assertUnprocessable();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->subDays(10)->toDateString(),
    ])->assertUnprocessable();
});

it('denies editing the fecha de desembolso once the crédito was refrendado', function () {
    $creditoId = desembolsarCreditoDeTres($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $interes = app(CreditoService::class)
        ->calcularMontoRefrendo(Credito::find($creditoId))['interes'];

    $this->postJson("/api/creditos-prendarios/{$creditoId}/refrendar", [
        'monto_pagado' => $interes, 'medio' => 'efectivo',
    ])->assertCreated();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->subDays(10)->toDateString(),
    ])->assertUnprocessable();
});

it('rejects a fecha de desembolso in the future', function () {
    $creditoId = desembolsarCreditoDeTres($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->addDay()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['fecha_desembolso']);
});

it('denies asesor from editing the fecha de desembolso', function () {
    $creditoId = desembolsarCreditoDeTres($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-fecha-desembolso", [
        'fecha_desembolso' => now()->subDays(10)->toDateString(),
    ])->assertForbidden();
});
