<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Factories\CobroFactory;
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
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'tasa_mora_diaria' => 1, 'max_cuotas' => 12,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 2000]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

/**
 * Registra, aprueba, firma documentos y desembolsa un crédito mensual a 1
 * cuota (simulando el caso real reportado: se desembolsó a 1 cuota cuando
 * debía ser a varias); retorna su id.
 */
function desembolsarCreditoAUnaCuota($test): int
{
    Sanctum::actingAs($test->asesor, ['*']);
    $creditoId = $test->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$test->bien->id],
        'monto_prestamo' => 1200, 'tipo_cuota' => 'mensual', 'numero_cuotas' => 1,
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

it('lets an admin correct the número de cuotas of an activo crédito and regenerates the cronograma', function () {
    $creditoId = desembolsarCreditoAUnaCuota($this);
    $credito = Credito::find($creditoId);
    expect($credito->numero_cuotas)->toBe(1)
        ->and($credito->cuotas)->toHaveCount(1);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-numero-cuotas", [
        'numero_cuotas' => 12,
    ])->assertSuccessful();

    $credito = Credito::find($creditoId)->load('cuotas');

    expect($credito->numero_cuotas)->toBe(12)
        ->and($credito->plazo_dias)->toBe(30 * 12)
        ->and($credito->fecha_vencimiento->toDateString())->toBe($credito->fecha_desembolso->copy()->addDays(30 * 12)->toDateString())
        ->and($credito->cuotas)->toHaveCount(12);

    // El capital total de las cuotas sigue cuadrando con el monto prestado.
    expect((float) $credito->cuotas->sum('monto_capital'))->toBe(1200.0);

    foreach ($credito->cuotas as $cuota) {
        expect($cuota->fecha_vencimiento->toDateString())
            ->toBe($credito->fecha_desembolso->copy()->addDays(30 * $cuota->numero_cuota)->toDateString());
    }
});

it('lets an admin fix the número de cuotas while pendiente, without touching cuotas', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 1200, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-numero-cuotas", [
        'numero_cuotas' => 12,
    ])->assertSuccessful();

    $credito = Credito::find($creditoId);
    expect($credito->numero_cuotas)->toBe(12)
        ->and($credito->cuotas()->exists())->toBeFalse()
        ->and($credito->estado)->toBe('pendiente');
});

it('denies correcting the número de cuotas once cobros were registered', function () {
    $creditoId = desembolsarCreditoAUnaCuota($this);
    $credito = Credito::find($creditoId);

    CobroFactory::new()->create([
        'empresa_id' => $credito->empresa_id,
        'cliente_id' => $credito->cliente_id,
        'credito_id' => $credito->id,
        'registrado_por' => $this->asesor->id,
        'operacion' => 'liquidacion',
    ]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-numero-cuotas", [
        'numero_cuotas' => 12,
    ])->assertUnprocessable();

    // El cronograma no se tocó.
    expect(Credito::find($creditoId)->numero_cuotas)->toBe(1);
});

it('rejects a número de cuotas above the configured max', function () {
    $creditoId = desembolsarCreditoAUnaCuota($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-numero-cuotas", [
        'numero_cuotas' => 13,
    ])->assertUnprocessable()->assertJsonValidationErrors(['numero_cuotas']);
});

it('denies asesor from editing the número de cuotas', function () {
    $creditoId = desembolsarCreditoAUnaCuota($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-numero-cuotas", [
        'numero_cuotas' => 12,
    ])->assertForbidden();
});
