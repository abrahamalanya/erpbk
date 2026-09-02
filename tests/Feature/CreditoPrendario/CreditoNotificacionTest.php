<?php

use App\Modules\Caja\Events\CajaActualizada;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Events\NotificacionCreada;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

it('notifies the controlling administrador_agencia when an asesor registers a solicitud', function () {
    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->adminAgencia->id
        && $event->notificacion->data['url'] === '/creditos-prendarios'
        && str_contains($event->notificacion->data['mensaje'], 'pendiente'));
});

it('notifies the registering asesor when their crédito is rechazado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Debe subir fotos'])
        ->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id
        && $event->notificacion->data['url'] === '/creditos-prendarios'
        && str_contains($event->notificacion->data['mensaje'], 'rechazado'));
});

it('notifies the registering asesor (not the aprobador) when their crédito is aprobado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id
        && $event->notificacion->data['url'] === '/creditos-prendarios'
        && str_contains($event->notificacion->data['mensaje'], 'aprobado'));
    Event::assertNotDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->adminAgencia->id);
});

it('notifies the controlling administrador_agencia when an asesor subsana a rechazado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Debe subir fotos'])
        ->assertSuccessful();

    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/subsanar")->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->adminAgencia->id
        && $event->notificacion->data['url'] === '/creditos-prendarios'
        && str_contains($event->notificacion->data['mensaje'], 'subsanado'));
});

it('notifies the registering asesor when an aprobación is reverted', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Event::fake([NotificacionCreada::class]);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-aprobacion")->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id
        && $event->notificacion->data['url'] === '/creditos-prendarios'
        && str_contains($event->notificacion->data['mensaje'], 'revertida'));
});

it('notifies the registering asesor when an admin desembolsa their crédito', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    $cajaAdmin = Caja::factory()->create(['user_id' => $this->adminAgencia->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $cajaAdmin->id, 'empresa_id' => $cajaAdmin->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);

    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id
        && $event->notificacion->data['url'] === '/creditos-prendarios'
        && str_contains($event->notificacion->data['mensaje'], 'desembolsado'));
});

it('notifies the registering asesor when an admin edits the interest rate', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/actualizar-interes", ['interes' => 5])
        ->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id
        && $event->notificacion->data['url'] === '/creditos-prendarios'
        && str_contains($event->notificacion->data['mensaje'], 'actualizada'));
});

it('notifies the registering asesor when their crédito is refrendado', function () {
    $activo = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Event::fake([NotificacionCreada::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$activo->id}")->json('data.monto_refrendo_sugerido.total');
    $response = $this->postJson("/api/creditos-prendarios/{$activo->id}/refrendar", ['monto_pagado' => $sugerido, 'medio' => 'efectivo'])
        ->assertCreated();

    $nuevoId = $response->json('data.id');

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id
        && $event->notificacion->data['credito_id'] === $nuevoId
        && str_contains($event->notificacion->data['mensaje'], 'refrendado'));
});

it('notifies the registering asesor once the acta de devolución is firmada, not right at liquidar', function () {
    Storage::fake('public');

    $activo = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Sanctum::actingAs($this->asesor, ['*']);

    Event::fake([NotificacionCreada::class]);
    $this->postJson("/api/creditos-prendarios/{$activo->id}/liquidar", ['monto_pagado' => 1000000, 'medio' => 'efectivo'])
        ->assertSuccessful();

    Event::assertNotDispatched(NotificacionCreada::class);

    $devolucion = Credito::find($activo->id)->documentos()->where('tipo', 'devolucion')->firstOrFail();
    $this->postJson("/api/creditos-prendarios/{$activo->id}/documentos/{$devolucion->id}/subir-firmado", [
        'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
    ])->assertSuccessful();

    Event::assertDispatched(NotificacionCreada::class, fn (NotificacionCreada $event): bool => $event->destinatario->id === $this->asesor->id
        && $event->notificacion->data['credito_id'] === $activo->id
        && str_contains($event->notificacion->data['mensaje'], 'liquidado'));
});

it('broadcasts CajaActualizada for the actor desembolsando (not just the CreditoActualizado event)', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    foreach (Credito::find($creditoId)->documentos as $documento) {
        $this->postJson("/api/creditos-prendarios/{$creditoId}/documentos/{$documento->id}/subir-firmado", [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
        ])->assertSuccessful();
    }

    Event::fake([CajaActualizada::class]);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    Event::assertDispatched(CajaActualizada::class, fn (CajaActualizada $event): bool => $event->caja->user_id === $this->asesor->id
        && $event->saldoActual === '9500.00');
});
