<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Events\NotificacionCreada;
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

it('notifies the asesor (not the emisor) when a billetaje is enviado, live via NotificacionCreada', function () {
    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->assertCreated();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id
        && $event->notificacion->data['url'] === '/billetajes');
    Event::assertNotDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->administradorAgencia->id);
});

it('notifies the emisor (not the asesor) when the billetaje is aceptado', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $billetajeId = $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->json('data.id');

    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aceptar")->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->administradorAgencia->id
        && $event->notificacion->data['url'] === '/billetajes'
        && str_contains($event->notificacion->data['mensaje'], 'aceptado'));
    Event::assertNotDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id);
});

it('stores the notification in the database with a mensaje and url, and lists it as unread for the asesor', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->assertCreated();

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/notificaciones')->assertSuccessful();

    expect($response->json('data.no_leidas'))->toBe(1)
        ->and($response->json('data.notificaciones.data.0.data.url'))->toBe('/billetajes')
        ->and($response->json('data.notificaciones.data.0.data.mensaje'))->toContain('billetaje')
        ->and($response->json('data.notificaciones.data.0.read_at'))->toBeNull();
});

it('allows marking a single notification as read, and denies marking someone else\'s', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->assertCreated();

    Sanctum::actingAs($this->asesor, ['*']);
    $notificacionId = $this->asesor->notifications()->firstOrFail()->id;

    Sanctum::actingAs($otroAsesor, ['*']);
    $this->postJson("/api/notificaciones/{$notificacionId}/marcar-leido")->assertNotFound();

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson("/api/notificaciones/{$notificacionId}/marcar-leido")->assertSuccessful();

    expect($response->json('data.read_at'))->not->toBeNull()
        ->and($this->asesor->unreadNotifications()->count())->toBe(0);
});

it('allows marking every notification as read at once', function () {
    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 100, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->assertCreated();
    $this->postJson('/api/billetajes', ['asesor_id' => $this->asesor->id, 'monto' => 50, 'motivo' => 'Gastos operativos', 'medio_egreso' => 'efectivo'])->assertCreated();

    Sanctum::actingAs($this->asesor, ['*']);
    expect($this->asesor->unreadNotifications()->count())->toBe(2);

    $this->postJson('/api/notificaciones/marcar-todas-leidas')->assertSuccessful();

    expect($this->asesor->unreadNotifications()->count())->toBe(0);
});
