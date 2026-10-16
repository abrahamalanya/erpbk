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

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaAgenciaId}/inyectar", ['monto' => 500])->assertCreated();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
});

it('broadcasts to the asesor and the controlling administrador_agencia when a billetaje is enviado', function () {
    Event::fake([BilletajeActualizado::class]);

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->assertCreated();

    Event::assertDispatched(BilletajeActualizado::class, function (BilletajeActualizado $event): bool {
        $destinatarioIds = $event->destinatarios->pluck('id')->all();

        return $event->billetaje->estado === 'pendiente'
            && in_array($this->asesor->id, $destinatarioIds, true)
            && in_array($this->administradorAgencia->id, $destinatarioIds, true);
    });
});

it('broadcasts to the asesor and the emisor when a billetaje is aceptado', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->json('data.id');

    Event::fake([BilletajeActualizado::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")->assertSuccessful();

    Event::assertDispatched(BilletajeActualizado::class, fn (BilletajeActualizado $event): bool => $event->billetaje->id === $billetajeId
        && $event->billetaje->estado === 'aprobado'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id)
        && $event->destinatarios->pluck('id')->contains($this->administradorAgencia->id));
});

it('broadcasts to the asesor and the emisor when a billetaje is rechazado', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->json('data.id');

    Event::fake([BilletajeActualizado::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/rechazar")->assertSuccessful();

    Event::assertDispatched(BilletajeActualizado::class, fn (BilletajeActualizado $event): bool => $event->billetaje->id === $billetajeId
        && $event->billetaje->estado === 'rechazado'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id)
        && $event->destinatarios->pluck('id')->contains($this->administradorAgencia->id));
});
