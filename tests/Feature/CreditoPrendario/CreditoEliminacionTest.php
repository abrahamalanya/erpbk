<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\CreditoPrendario\Models\Bien;
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
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
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

    $this->registrarCreditoPendiente = function (): int {
        Sanctum::actingAs($this->asesor, ['*']);

        return $this->postJson('/api/creditos-prendarios', [
            'bien_ids' => [$this->bien->id],
            'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
        ])->assertCreated()->json('data.id');
    };
});

it('lets an admin delete a pendiente crédito and frees its garantía', function () {
    $creditoId = ($this->registrarCreditoPendiente)();

    expect(DocumentoCredito::where('credito_id', $creditoId)->count())->toBeGreaterThan(0);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->deleteJson("/api/creditos-prendarios/{$creditoId}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Crédito eliminado');

    expect(Credito::find($creditoId))->toBeNull()
        ->and(DocumentoCredito::where('credito_id', $creditoId)->count())->toBe(0)
        ->and(Bien::disponibles()->whereKey($this->bien->id)->exists())->toBeTrue();
});

it('lets an admin delete a rechazado crédito', function () {
    $creditoId = ($this->registrarCreditoPendiente)();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'faltan fotos'])->assertSuccessful();

    $this->deleteJson("/api/creditos-prendarios/{$creditoId}")->assertSuccessful();

    expect(Credito::find($creditoId))->toBeNull();
});

it('refuses to delete a crédito once it is aprobado', function () {
    $creditoId = ($this->registrarCreditoPendiente)();

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    $this->deleteJson("/api/creditos-prendarios/{$creditoId}")->assertUnprocessable();

    expect(Credito::find($creditoId))->not->toBeNull();
});

it('forbids an asesor without the eliminar permission from deleting a crédito', function () {
    $creditoId = ($this->registrarCreditoPendiente)();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->deleteJson("/api/creditos-prendarios/{$creditoId}")->assertForbidden();

    expect(Credito::find($creditoId))->not->toBeNull();
});

it('scopes deletion to an admin with authority over the crédito', function () {
    $creditoId = ($this->registrarCreditoPendiente)();

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $adminOtraAgencia = User::factory()->forAgencia($otraAgencia)->create();
    $adminOtraAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($adminOtraAgencia, ['*']);
    $this->deleteJson("/api/creditos-prendarios/{$creditoId}")->assertForbidden();

    expect(Credito::find($creditoId))->not->toBeNull();
});
