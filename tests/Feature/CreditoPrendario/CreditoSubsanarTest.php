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

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

it('requires a motivo to reject a crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['motivo']);
});

it('lets the asesor subsanar a rechazado crédito, sending it back to pendiente', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Debe subir fotos del producto'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'rechazado');

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$creditoId}/subsanar")->assertSuccessful();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.motivo_rechazo'))->toBe('Debe subir fotos del producto');

    // El admin puede volver a revisarlo con normalidad.
    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'aprobado');
});

it('denies administrador_agencia (who rejected it) from subsanar-ing the crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Debe subir fotos del producto'])
        ->assertSuccessful();

    $this->postJson("/api/creditos-prendarios/{$creditoId}/subsanar")->assertForbidden();
});

it('denies subsanar-ing a crédito that is not rechazado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/creditos-prendarios/{$creditoId}/subsanar")->assertUnprocessable();
});

it('denies subsanar-ing a crédito whose bienes now back a different active crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Documentación insuficiente'])
        ->assertSuccessful();

    // El bien queda libre tras el rechazo — otro crédito lo puede tomar.
    CreditoPrendario::factory()->paraBien($this->bien)->create(['estado' => 'activo']);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/subsanar")->assertUnprocessable();
});
