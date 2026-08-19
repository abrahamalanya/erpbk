<?php

use App\Modules\Caja\Events\BovedaActualizada;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
});

it('exposes the principal bóveda with saldo_actual on GET /bovedas/mia for administrador_general', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);

    $this->postJson('/api/bovedas/mia')->assertMethodNotAllowed();

    $response = $this->getJson('/api/bovedas/mia')->assertSuccessful();

    expect($response->json('data.tipo'))->toBe('principal')
        ->and($response->json('data.ciclo_abierto'))->toBeNull();
});

it('exposes the agencia bóveda for administrador_agencia, with a live saldo_actual once opened', function () {
    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);

    $bovedaId = $this->getJson('/api/bovedas/mia')->assertSuccessful()->json('data.id');
    expect($this->getJson('/api/bovedas/mia')->json('data.ciclo_abierto'))->toBeNull();

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $bovedaPrincipalId = $this->getJson('/api/bovedas/mia')->json('data.id');
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaId}/inyectar", ['monto' => 300])->assertCreated();

    Sanctum::actingAs($administradorAgencia, ['*']);
    $response = $this->getJson('/api/bovedas/mia')->assertSuccessful();

    expect($response->json('data.ciclo_abierto.saldo_actual'))->toBe('300.00');
});

it('denies GET /bovedas/mia for roles that do not control a bóveda', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->getJson('/api/bovedas/mia')->assertForbidden();
});

it('broadcasts to every administrador_agencia controlling the bóveda when it receives a traspaso', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');

    Event::fake([BovedaActualizada::class]);

    $this->postJson("/api/bovedas/{$bovedaAgenciaId}/inyectar", ['monto' => 250])->assertCreated();

    Event::assertDispatched(BovedaActualizada::class, function (BovedaActualizada $event) use ($bovedaAgenciaId, $administradorAgencia): bool {
        if ((int) $event->boveda->id !== (int) $bovedaAgenciaId) {
            return false;
        }

        return $event->saldoActual === '250.00'
            && $event->destinatarios->pluck('id')->contains($administradorAgencia->id);
    });
});

it('broadcasts saldo_actual null when a bóveda is cerrada', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $bovedaPrincipalId = $this->getJson('/api/bovedas/mia')->json('data.id');
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();

    Event::fake([BovedaActualizada::class]);

    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/cerrar", ['monto_contado' => 1000])->assertSuccessful();

    Event::assertDispatched(BovedaActualizada::class, fn (BovedaActualizada $event): bool => (int) $event->boveda->id === (int) $bovedaPrincipalId
        && $event->saldoActual === null);
});
