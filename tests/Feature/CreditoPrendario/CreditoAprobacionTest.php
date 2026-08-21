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
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);
});

it('allows the registering asesor to view the crédito via show', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/creditos-prendarios/{$creditoId}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $creditoId);
});

it('registers a crédito as pendiente with the config default interest', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.interes'))->toBe('10.00');
});

it('allows administrador_agencia to approve and generates contrato + declaracion', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('aprobado');

    $tipos = CreditoPrendario::find($creditoId)
        ->documentos()->pluck('tipo')->sort()->values()->all();
    expect($tipos)->toBe(['contrato', 'declaracion']);
});

it('allows administrador_general to reject with a motivo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $adminGeneral->assignRole('administrador_general');
    Sanctum::actingAs($adminGeneral, ['*']);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Documentación insuficiente'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'rechazado')
        ->assertJsonPath('data.motivo_rechazo', 'Documentación insuficiente');
});

it('denies asesor from approving their own crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertForbidden();
});

it('activates the crédito once desembolsado, setting fecha_desembolso, fecha_vencimiento and the cronograma', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
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

    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('activo')
        ->and($response->json('data.fecha_desembolso'))->not->toBeNull()
        ->and($response->json('data.fecha_vencimiento'))->not->toBeNull();

    $pendientesDeFirma = CreditoPrendario::find($creditoId)
        ->documentos()->whereNull('firmado_at')->count();
    expect($pendientesDeFirma)->toBe(0);

    // mensual -> 1 cuota (tabla fija); capital amortizado + interés sobre saldo insoluto
    $cuotas = CreditoPrendario::find($creditoId)->cuotas;
    expect($cuotas)->toHaveCount(1)
        ->and((string) $cuotas->first()->monto_capital)->toBe('500.00')
        ->and((string) $cuotas->first()->monto_total)->toBe('550.00');
});

it('rejects desembolsar when the crédito is not yet aprobado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertUnprocessable();
});

it('rejects desembolsar when a documento is not yet firmado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    Caja::query()->where('user_id', $this->asesor->id)->first()->cicloAbierto->update(['saldo_apertura' => 10000]);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertUnprocessable();
});

it('rejects desembolsar when the actor caja does not have enough saldo', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
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

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No tienes saldo suficiente en tu caja para desembolsar este crédito.');
});
