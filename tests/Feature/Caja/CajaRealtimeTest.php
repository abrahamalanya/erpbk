<?php

use App\Modules\Caja\Events\CajaActualizada;
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

it('broadcasts to its own owner when a caja is aperturada, with saldo 0', function () {
    Event::fake([CajaActualizada::class]);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    Event::assertDispatched(CajaActualizada::class, fn (CajaActualizada $event): bool => $event->caja->user_id === $asesor->id
        && $event->saldoActual === '0.00');
});

it('broadcasts to its own owner (not the emisor) when a billetaje aceptado moves the saldo', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaAgenciaId}/inyectar", ['monto' => 500])->assertCreated();

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', ['asesor_id' => $asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->json('data.id');

    Event::fake([CajaActualizada::class]);

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")->assertSuccessful();

    Event::assertDispatched(CajaActualizada::class, fn (CajaActualizada $event): bool => $event->caja->user_id === $asesor->id
        && $event->saldoActual === '100.00');
});

it('broadcasts saldo_actual null when a caja is cerrada', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    Event::fake([CajaActualizada::class]);

    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    Event::assertDispatched(CajaActualizada::class, fn (CajaActualizada $event): bool => $event->caja->user_id === $asesor->id
        && $event->saldoActual === null);
});
