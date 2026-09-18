<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Services\ModuloService;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05, 'max_cuotas' => 45,
    ]);

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

it('has no restriction by default, matching today\'s behavior', function () {
    expect(app(ModuloService::class)->asignadosA($this->asesor))->toBeNull();

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
    ])->assertCreated();
});

it('blocks registering a credit outside the assigned module', function () {
    app(ModuloService::class)->asignar($this->asesor, ['prendario']);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
    ])->assertForbidden();
});

it('allows registering a credit inside the assigned module', function () {
    app(ModuloService::class)->asignar($this->asesor, ['diario']);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-diarios', [
        'cliente_id' => $this->cliente->id,
        'monto_prestamo' => 500,
        'tipo_cuota' => 'diario',
    ])->assertCreated();
});

it('lets an admin restrict, then clear, an asesor\'s modules via the API', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->putJson("/api/usuarios/{$this->asesor->id}/modulos", ['modulos' => ['prendario']])
        ->assertSuccessful()
        ->assertJsonPath('data.asignados', ['prendario']);

    $this->putJson("/api/usuarios/{$this->asesor->id}/modulos", ['modulos' => null])
        ->assertSuccessful()
        ->assertJsonPath('data.asignados', null);

    expect(app(ModuloService::class)->asignadosA($this->asesor->refresh()))->toBeNull();
});

it('rejects module assignment for a role not managed by modules', function () {
    $secretaria = User::factory()->forAgencia($this->agencia)->create();
    $secretaria->assignRole('secretaria');

    Sanctum::actingAs($this->admin, ['*']);

    $this->putJson("/api/usuarios/{$secretaria->id}/modulos", ['modulos' => ['prendario']])
        ->assertUnprocessable();
});

it('denies module assignment to an actor without usuarios.editar', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->putJson("/api/usuarios/{$this->asesor->id}/modulos", ['modulos' => ['prendario']])
        ->assertForbidden();
});
