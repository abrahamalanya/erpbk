<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Events\CreditoActualizado;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
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
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 1000]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

it('broadcasts to the asesor and the controlling administrador_agencia when a crédito is solicitado', function () {
    Event::fake([CreditoActualizado::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated();

    Event::assertDispatched(CreditoActualizado::class, function (CreditoActualizado $event): bool {
        $destinatarioIds = $event->destinatarios->pluck('id')->all();

        return $event->credito->estado === 'pendiente'
            && in_array($this->asesor->id, $destinatarioIds, true)
            && in_array($this->adminAgencia->id, $destinatarioIds, true);
    });
});

it('broadcasts to the asesor when a crédito is aprobado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Event::fake([CreditoActualizado::class]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $creditoId
        && $event->credito->estado === 'aprobado'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id));
});

it('broadcasts to the asesor when a crédito is rechazado, and again when subsanado', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Event::fake([CreditoActualizado::class]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/rechazar", ['motivo' => 'Debe subir fotos'])
        ->assertSuccessful();

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $creditoId
        && $event->credito->estado === 'rechazado'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id));

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/subsanar")->assertSuccessful();

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $creditoId
        && $event->credito->estado === 'pendiente'
        && $event->destinatarios->pluck('id')->contains($this->adminAgencia->id));
});

it('broadcasts the new chained crédito to the asesor and controlling administrador_agencia on refrendo', function () {
    $activo = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Event::fake([CreditoActualizado::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $sugerido = $this->getJson("/api/creditos-prendarios/{$activo->id}")->json('data.monto_refrendo_sugerido.total');
    $response = $this->postJson("/api/creditos-prendarios/{$activo->id}/refrendar", ['monto_pagado' => $sugerido, 'medio' => 'efectivo'])
        ->assertCreated();

    $nuevoId = $response->json('data.id');

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $nuevoId
        && $event->credito->estado === 'activo'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id)
        && $event->destinatarios->pluck('id')->contains($this->adminAgencia->id));
});

it('broadcasts to the asesor and controlling administrador_agencia when a crédito is liquidado_pendiente', function () {
    $activo = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Event::fake([CreditoActualizado::class]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$activo->id}/liquidar", ['monto_pagado' => 1000000, 'medio' => 'efectivo'])
        ->assertSuccessful();

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $activo->id
        && $event->credito->estado === 'liquidado_pendiente'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id)
        && $event->destinatarios->pluck('id')->contains($this->adminAgencia->id));
});

it('broadcasts a second time, now as liquidado, once the acta de devolución is firmada', function () {
    Storage::fake('public');

    $activo = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$activo->id}/liquidar", ['monto_pagado' => 1000000, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $devolucion = Credito::find($activo->id)->documentos()->where('tipo', 'devolucion')->firstOrFail();

    Event::fake([CreditoActualizado::class]);
    $this->postJson("/api/creditos-prendarios/{$activo->id}/documentos/{$devolucion->id}/subir-firmado", [
        'archivo' => UploadedFile::fake()->create('firmado.pdf', 100, 'application/pdf'),
    ])->assertSuccessful();

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $activo->id
        && $event->credito->estado === 'liquidado'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id)
        && $event->destinatarios->pluck('id')->contains($this->adminAgencia->id));
});

it('broadcasts to the asesor when a crédito aprobado is reverted back to pendiente', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$this->bien->id],
        'monto_prestamo' => 500, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Event::fake([CreditoActualizado::class]);

    $this->postJson("/api/creditos-prendarios/{$creditoId}/revertir-aprobacion")->assertSuccessful();

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $creditoId
        && $event->credito->estado === 'pendiente'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id));
});

it('broadcasts to the asesor and controlling administrador_agencia when actualizarEstadosVencidos transiciona activo a vencido', function () {
    $activo = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create([
            'registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
            'fecha_vencimiento' => now()->subDay()->toDateString(),
        ]);

    Event::fake([CreditoActualizado::class]);

    app(CreditoService::class)->actualizarEstadosVencidos();

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $activo->id
        && $event->credito->estado === 'vencido'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id)
        && $event->destinatarios->pluck('id')->contains($this->adminAgencia->id));
});

it('broadcasts to the asesor and controlling administrador_agencia when actualizarEstadosVencidos transiciona vencido a en_venta', function () {
    $vencido = Credito::factory()->paraBien($this->bien)
        ->vencido(diasVencido: 20)
        ->create(['registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);

    Event::fake([CreditoActualizado::class]);

    app(CreditoService::class)->actualizarEstadosVencidos();

    Event::assertDispatched(CreditoActualizado::class, fn (CreditoActualizado $event): bool => $event->credito->id === $vencido->id
        && $event->credito->estado === 'en_venta'
        && $event->destinatarios->pluck('id')->contains($this->asesor->id)
        && $event->destinatarios->pluck('id')->contains($this->adminAgencia->id));

    expect($this->bien->fresh()->estado)->toBe('disponible_venta');
});
