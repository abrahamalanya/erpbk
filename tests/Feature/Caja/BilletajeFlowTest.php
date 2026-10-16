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

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');

    // Fund the agencia bóveda that finances the asesor's caja — must happen
    // BEFORE the caja apertura (which would otherwise auto-open the bóveda
    // at saldo 0 and block the explicit apertura with saldo_inicial).
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $this->bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaAgenciaId}/inyectar", ['monto' => 1000])->assertCreated();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
});

it('lets administrador_agencia send a billetaje to an asesor with an open caja', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);

    $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 150,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.asesor_id', $this->asesor->id)
        ->assertJsonPath('data.solicitado_por.id', $this->administradorAgencia->id);
});

it('rejects sending a billetaje to an asesor without an open caja', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Sanctum::actingAs($this->administradorAgencia, ['*']);

    $this->postJson('/api/billetajes', [
        'asesor_id' => $otroAsesor->id,
        'monto' => 100,
        'motivo' => 'Gastos operativos',
    ])->assertUnprocessable()->assertJsonValidationErrors('asesor_id');
});

it('rejects sending a billetaje to a user who is not an asesor', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);

    $this->postJson('/api/billetajes', [
        'asesor_id' => $this->administradorAgencia->id,
        'monto' => 100,
        'motivo' => 'Gastos operativos',
    ])->assertUnprocessable()->assertJsonValidationErrors('asesor_id');
});

it('lets sistemas, administrador_general and administrador_agencia send, but not asesor, supervisor or secretaria', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 10, 'motivo' => 'x'])->assertForbidden();

    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 10, 'motivo' => 'x'])->assertForbidden();

    $secretaria = User::factory()->forEmpresa($this->empresa)->create();
    $secretaria->assignRole('secretaria');
    Sanctum::actingAs($secretaria, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 10, 'motivo' => 'x'])->assertForbidden();

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 10, 'motivo' => 'x', 'medio_egreso' => 'efectivo'])->assertCreated();

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 10, 'motivo' => 'x', 'medio_egreso' => 'efectivo'])->assertCreated();
});

it('lets the asesor aceptar the billetaje, moving the cash on both ledgers', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 150,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->json('data.id');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'aprobado')
        ->assertJsonPath('data.aprobado_por', $this->asesor->id);

    $boveda = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaCiclo = $boveda->cicloAbierto()->firstOrFail();
    expect($bovedaCiclo->movimientos()->where('tipo', 'egreso')->sum('monto'))->toEqual(150);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/caja')->assertSuccessful()->assertJsonPath('data.saldo_actual', '150.00');
});

it('lets the asesor rechazar the billetaje with a motivo', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 80,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->json('data.id');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/rechazar", ['motivo' => 'No corresponde'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'rechazado')
        ->assertJsonPath('data.motivo_rechazo', 'No corresponde');

    $boveda = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    expect($boveda->cicloAbierto()->firstOrFail()->movimientos()->where('tipo', 'egreso')->sum('monto'))->toEqual(0);
});

it('denies a different asesor from accepting a billetaje addressed to someone else', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 80,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->json('data.id');

    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Sanctum::actingAs($otroAsesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")->assertForbidden();
    $this->postJson("/api/billetajes/{$billetajeId}/rechazar")->assertForbidden();
});

it('denies the sending administrator from accepting or rejecting their own billetaje', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 80,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->json('data.id');

    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")->assertForbidden();
    $this->postJson("/api/billetajes/{$billetajeId}/rechazar")->assertForbidden();
});

it('denies an administrador_agencia from another agencia from sending to this asesor', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroAdministrador = User::factory()->forAgencia($otraAgencia)->create();
    $otroAdministrador->assignRole('administrador_agencia');

    Sanctum::actingAs($otroAdministrador, ['*']);
    $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 80,
        'motivo' => 'Gastos operativos',
    ])->assertUnprocessable()->assertJsonValidationErrors('asesor_id');
});

it('lets the administrador_general send to an asesor of any agencia of the empresa', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $asesorOtraAgencia = User::factory()->forAgencia($otraAgencia)->create();
    $asesorOtraAgencia->assignRole('asesor');
    Sanctum::actingAs($asesorOtraAgencia, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->postJson('/api/billetajes', [
        'asesor_id' => $asesorOtraAgencia->id,
        'monto' => 80,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->assertCreated();
});

it('lets sistemas send a billetaje cross-empresa', function () {
    $sistemas = User::factory()->create(['empresa_id' => null, 'agencia_id' => null]);
    $sistemas->assignRole('sistemas');

    Sanctum::actingAs($sistemas, ['*']);
    $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 80,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->assertCreated();
});

it('denies accepting a billetaje when the funding boveda does not have enough saldo', function () {
    $sinSaldo = Agencia::factory()->for($this->empresa)->create();
    $asesorSinSaldo = User::factory()->forAgencia($sinSaldo)->create();
    $asesorSinSaldo->assignRole('asesor');
    Sanctum::actingAs($asesorSinSaldo, ['*']);
    // Auto-opens the agencia bóveda (and principal) at saldo 0.
    $this->postJson('/api/caja/aperturar')->assertCreated();

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $asesorSinSaldo->id,
        'monto' => 150,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->json('data.id');

    Sanctum::actingAs($asesorSinSaldo, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'La bóveda no tiene saldo suficiente para entregar este billetaje.');
});

it('allows several pending billetajes at once for the same asesor', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'x', 'medio_egreso' => 'efectivo'])->assertCreated();
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 50, 'motivo' => 'x', 'medio_egreso' => 'efectivo'])->assertCreated();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/billetajes')->assertSuccessful()->assertJsonCount(2, 'data.data');
});

it('keeps the billetaje scoped to its empresa (another empresa admin does not find it)', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 80,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->json('data.id');

    $otraEmpresa = Empresa::factory()->create();
    $adminOtraEmpresa = User::factory()->forEmpresa($otraEmpresa)->create();
    $adminOtraEmpresa->assignRole('administrador_general');

    Sanctum::actingAs($adminOtraEmpresa, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")->assertNotFound();
});

it('lets a force-closing superior auto-reject a pending billetaje', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', [
        'asesor_id' => $this->asesor->id,
        'monto' => 100,
        'motivo' => 'Gastos operativos',
        'medio_egreso' => 'efectivo',
    ])->assertCreated();

    $caja = Caja::query()->where('user_id', $this->asesor->id)->firstOrFail();

    $this->postJson("/api/cajas/{$caja->id}/cerrar-forzado", ['monto_contado' => 0])->assertSuccessful();

    expect($caja->ciclos()->latest()->firstOrFail()->billetajes()->latest()->firstOrFail()->estado)->toBe('rechazado');
});
