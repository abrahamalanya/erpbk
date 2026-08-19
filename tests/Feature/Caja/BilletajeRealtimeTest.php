<?php

use App\Modules\Caja\Events\BilletajeActualizado;
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

it('broadcasts to the solicitante and the controlling administrador_agencia when a billetaje is solicitado', function () {
    Event::fake([BilletajeActualizado::class]);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 100])->assertCreated();

    Event::assertDispatched(BilletajeActualizado::class, function (BilletajeActualizado $event) use ($asesor, $administradorAgencia): bool {
        $destinatarioIds = $event->destinatarios->pluck('id')->all();

        return $event->billetaje->estado === 'pendiente'
            && in_array($asesor->id, $destinatarioIds, true)
            && in_array($administradorAgencia->id, $destinatarioIds, true);
    });
});

it('broadcasts to the solicitante when a billetaje is aprobado', function () {
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
    $billetajeId = $this->postJson('/api/billetajes', ['monto' => 100])->json('data.id');

    Event::fake([BilletajeActualizado::class]);

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")->assertSuccessful();

    Event::assertDispatched(BilletajeActualizado::class, fn (BilletajeActualizado $event): bool => $event->billetaje->id === $billetajeId
        && $event->billetaje->estado === 'aprobado'
        && $event->destinatarios->pluck('id')->contains($asesor->id));
});

it('broadcasts to the solicitante when a billetaje is rechazado', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', ['monto' => 100])->json('data.id');

    Event::fake([BilletajeActualizado::class]);

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/rechazar")->assertSuccessful();

    Event::assertDispatched(BilletajeActualizado::class, fn (BilletajeActualizado $event): bool => $event->billetaje->id === $billetajeId
        && $event->billetaje->estado === 'rechazado'
        && $event->destinatarios->pluck('id')->contains($asesor->id));
});
