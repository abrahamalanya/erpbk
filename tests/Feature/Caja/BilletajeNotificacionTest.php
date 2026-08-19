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
});

it('notifies the controlling administrador_agencia (not the solicitante) when a billetaje is solicitado, live via NotificacionCreada', function () {
    Event::fake([NotificacionCreada::class]);

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 100])->assertCreated();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $administradorAgencia->id
        && $event->notificacion->data['url'] === '/billetajes');
    Event::assertNotDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $asesor->id);
});

it('notifies the solicitante (not the aprobador) when their billetaje is aprobado', function () {
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

    Event::fake([NotificacionCreada::class]);

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $asesor->id
        && $event->notificacion->data['url'] === '/billetajes'
        && str_contains($event->notificacion->data['mensaje'], 'aprobada'));
    Event::assertNotDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $administradorAgencia->id);
});

it('stores the notification in the database with a mensaje and url, and lists it as unread', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 100])->assertCreated();

    Sanctum::actingAs($administradorAgencia, ['*']);
    $response = $this->getJson('/api/notificaciones')->assertSuccessful();

    expect($response->json('data.no_leidas'))->toBe(1)
        ->and($response->json('data.notificaciones.data.0.data.url'))->toBe('/billetajes')
        ->and($response->json('data.notificaciones.data.0.data.mensaje'))->toContain('billetaje')
        ->and($response->json('data.notificaciones.data.0.read_at'))->toBeNull();
});

it('allows marking a single notification as read, and denies marking someone else\'s', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    $otroAdmin = User::factory()->forAgencia($this->agencia)->create();
    $otroAdmin->assignRole('administrador_agencia');

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 100])->assertCreated();

    Sanctum::actingAs($administradorAgencia, ['*']);
    $notificacionId = $administradorAgencia->notifications()->firstOrFail()->id;

    Sanctum::actingAs($otroAdmin, ['*']);
    $this->postJson("/api/notificaciones/{$notificacionId}/marcar-leido")->assertNotFound();

    Sanctum::actingAs($administradorAgencia, ['*']);
    $response = $this->postJson("/api/notificaciones/{$notificacionId}/marcar-leido")->assertSuccessful();

    expect($response->json('data.read_at'))->not->toBeNull()
        ->and($administradorAgencia->unreadNotifications()->count())->toBe(0);
});

it('allows marking every notification as read at once', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 100])->assertCreated();
    $this->postJson('/api/billetajes', ['monto' => 50])->assertCreated();

    Sanctum::actingAs($administradorAgencia, ['*']);
    expect($administradorAgencia->unreadNotifications()->count())->toBe(2);

    $this->postJson('/api/notificaciones/marcar-todas-leidas')->assertSuccessful();

    expect($administradorAgencia->unreadNotifications()->count())->toBe(0);
});
