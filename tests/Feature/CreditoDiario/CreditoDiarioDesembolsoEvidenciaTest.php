<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoDiario\Models\CreditoDiarioDesembolsoEvidencia;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

beforeEach(function () {
    Storage::fake('public');
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05, 'max_cuotas' => 45,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

/** Registra, aprueba y firma un crédito diario — queda listo para desembolsar, sin evidencia. */
function crearCreditoDiarioAprobado(TestCase $test): int
{
    Sanctum::actingAs($test->asesor, ['*']);
    $creditoId = $test->postJson('/api/creditos-diarios', [
        'cliente_id' => $test->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($test->adminAgencia, ['*']);
    $test->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($test->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $test->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    return $creditoId;
}

it('rejects desembolsar when no evidence was ever uploaded', function () {
    $creditoId = crearCreditoDiarioAprobado($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Debes registrar el video de evidencia y la ubicación antes de desembolsar este crédito.');

    expect(Credito::find($creditoId)->estado)->toBe('aprobado');
});

it('rejects uploading evidence without a valid location', function () {
    $creditoId = crearCreditoDiarioAprobado($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/evidencia-desembolso", [
        'video' => UploadedFile::fake()->create('evidencia.mp4', 1024, 'video/mp4'),
        // sin latitud/longitud
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['latitud', 'longitud']);

    expect(CreditoDiarioDesembolsoEvidencia::query()->where('credito_id', $creditoId)->exists())->toBeFalse();
});

it('allows desembolsar once the video and location evidence is complete and confirmed', function () {
    $creditoId = crearCreditoDiarioAprobado($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $evidenciaResponse = $this->postJson("/api/creditos-prendarios/{$creditoId}/evidencia-desembolso", [
        'video' => UploadedFile::fake()->create('evidencia.mp4', 1024, 'video/mp4'),
        'latitud' => -8.379,
        'longitud' => -74.553,
        'precision_metros' => 8.5,
    ])->assertSuccessful();

    expect($evidenciaResponse->json('data.estado'))->toBe('cargada')
        ->and($evidenciaResponse->json('data.recibido_en'))->not->toBeNull()
        ->and($evidenciaResponse->json('data.confirmado_en'))->not->toBeNull();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    expect(Credito::find($creditoId)->estado)->toBe('activo');
});

it('denies a user without the desembolsar permission from uploading or confirming evidence', function () {
    $creditoId = crearCreditoDiarioAprobado($this);

    $secretaria = User::factory()->forAgencia($this->agencia)->create();
    $secretaria->assignRole('secretaria');
    Sanctum::actingAs($secretaria, ['*']);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/evidencia-desembolso", [
        'video' => UploadedFile::fake()->create('evidencia.mp4', 1024, 'video/mp4'),
        'latitud' => -8.379,
        'longitud' => -74.553,
    ])->assertForbidden();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertForbidden();
});

it('rejects a direct desembolsar API call with no evidence, even with valid credentials', function () {
    $creditoId = crearCreditoDiarioAprobado($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertStatus(422);

    expect(Credito::find($creditoId)->estado)->toBe('aprobado')
        ->and(CajaMovimiento::query()->where('credito_id', $creditoId)->exists())->toBeFalse();
});

it('replaces the previous evidence file on retry instead of duplicating the row', function () {
    $creditoId = crearCreditoDiarioAprobado($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/evidencia-desembolso", [
        'video' => UploadedFile::fake()->create('primero.mp4', 1024, 'video/mp4'),
        'latitud' => -8.379, 'longitud' => -74.553,
    ])->assertSuccessful();

    $primeraEvidencia = CreditoDiarioDesembolsoEvidencia::query()->where('credito_id', $creditoId)->first();
    $primerPath = $primeraEvidencia->video_path;
    Storage::disk('public')->assertExists($primerPath);

    // Reintento (p. ej. tras un error de red del lado del cliente): sube de nuevo.
    $this->postJson("/api/creditos-prendarios/{$creditoId}/evidencia-desembolso", [
        'video' => UploadedFile::fake()->create('segundo.mp4', 1024, 'video/mp4'),
        'latitud' => -8.380, 'longitud' => -74.554,
    ])->assertSuccessful();

    expect(CreditoDiarioDesembolsoEvidencia::query()->where('credito_id', $creditoId)->count())->toBe(1);
    Storage::disk('public')->assertMissing($primerPath);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();
    expect(CajaMovimiento::query()->where('credito_id', $creditoId)->count())->toBe(1);
});

it('does not duplicate the disbursement on a repeated desembolsar request for the same crédito', function () {
    $creditoId = crearCreditoDiarioAprobado($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/evidencia-desembolso", [
        'video' => UploadedFile::fake()->create('evidencia.mp4', 1024, 'video/mp4'),
        'latitud' => -8.379, 'longitud' => -74.553,
    ])->assertSuccessful();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();
    // Un segundo envío del mismo request (doble clic) ya no encuentra el
    // crédito en 'aprobado' — el lock de la fila dentro de la transacción es
    // lo que cierra la ventana de carrera entre dos requests simultáneos.
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertStatus(422);

    expect(CajaMovimiento::query()->where('credito_id', $creditoId)->count())->toBe(1)
        ->and(Credito::find($creditoId)->cuotas()->count())->toBeGreaterThan(0);
});

it('lets prendario/vehicular/hipotecario desembolsar without any video/GPS evidence, unaffected by the diario-only requirement', function () {
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'prendario',
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$bien->id],
        'monto_prestamo' => 400, 'tipo_cuota' => 'semanal',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    expect(Credito::find($creditoId)->estado)->toBe('activo');
});
