<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
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
});

it('lets administrador_agencia reabrir a closed caja, and re-closing updates the boveda movimiento instead of duplicating it', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 100])->assertSuccessful();

    $caja = Caja::query()->where('user_id', $asesor->id)->firstOrFail();
    $cicloId = $caja->ciclos()->latest()->firstOrFail()->id;

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/cajas/{$caja->id}/reabrir")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $cicloId)
        ->assertJsonPath('data.estado', 'abierta');

    expect($caja->ciclos()->count())->toBe(1);

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 150])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $cicloId)
        ->assertJsonPath('data.saldo_arqueo_cierre', '150.00');

    expect($caja->ciclos()->count())->toBe(1);

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $movimientos = $bovedaAgencia->cicloAbierto()->firstOrFail()
        ->movimientos()->where('caja_ciclo_id', $cicloId)->where('concepto', 'Entrega por cierre de caja')->get();

    expect($movimientos)->toHaveCount(1)
        ->and((string) $movimientos->first()->monto)->toBe('150.00');
});

it('denies a supervisor from reabrir-ing an asesor caja (wrong level)', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $caja = Caja::query()->where('user_id', $asesor->id)->firstOrFail();

    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    Sanctum::actingAs($supervisor, ['*']);

    $this->postJson("/api/cajas/{$caja->id}/reabrir")->assertForbidden();
});

it('rejects reabrir-ing a caja that has no closed ciclo, or that is already open', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $caja = Caja::query()->where('user_id', $asesor->id)->firstOrFail();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/cajas/{$caja->id}/reabrir")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Esta caja ya tiene un ciclo abierto.');
});

it('lets administrador_general reabrir the principal boveda after a normal cierre, and inyectar again', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $cicloId = Boveda::query()->findOrFail($bovedaPrincipalId)->cicloAbierto()->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/cerrar", ['monto_contado' => 1000])->assertSuccessful();

    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/reabrir")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $cicloId)
        ->assertJsonPath('data.estado', 'abierta');

    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/inyectar", ['monto' => 200])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/cerrar", ['monto_contado' => 1200])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $cicloId);
});

it('denies an administrador_agencia from reabrir-ing the principal boveda', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/cerrar", ['monto_contado' => 1000])->assertSuccessful();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/reabrir")->assertForbidden();
});
