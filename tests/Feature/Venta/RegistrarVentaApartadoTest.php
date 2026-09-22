<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\Venta;
use App\Modules\Venta\Services\VentaService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');

    $caja = Caja::factory()->create(['user_id' => $this->admin->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);
});

it('registers an apartado, reserving the artículo until it is paid off with free abonos', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 600]);

    $response = $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'apartado',
        'inicial' => 100,
        'fecha_limite' => now()->addDays(15)->toDateString(),
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('activa')
        ->and($response->json('data.saldo_pendiente'))->toBe('500.00');
    expect($bien->fresh()->estado)->toBe('reservada');

    $venta = Venta::findOrFail($response->json('data.id'));

    $this->postJson("/api/ventas/{$venta->id}/abonar", ['monto' => 300, 'medio' => 'yape'])
        ->assertSuccessful()
        ->assertJsonPath('data.saldo_pendiente', '200.00')
        ->assertJsonPath('data.estado', 'activa');

    $this->postJson("/api/ventas/{$venta->id}/abonar", ['monto' => 200, 'medio' => 'yape'])
        ->assertSuccessful()
        ->assertJsonPath('data.saldo_pendiente', '0.00')
        ->assertJsonPath('data.estado', 'pagada');

    expect($bien->fresh()->estado)->toBe('vendida');
    expect($venta->fresh()->documentos->pluck('tipo')->all())->toBe(['contrato_apartado', 'voucher']);
});

it('rejects an abono that exceeds the remaining saldo', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 600]);

    $venta = Venta::factory()->paraArticulo($bien)->create([
        'cliente_id' => $cliente->id, 'vendido_por' => $this->admin->id,
        'forma_venta' => 'apartado', 'estado' => 'activa',
        'precio_venta' => 600, 'inicial' => 100, 'saldo_pendiente' => 500,
        'fecha_limite' => now()->addDays(10),
    ]);

    $this->postJson("/api/ventas/{$venta->id}/abonar", ['monto' => 600, 'medio' => 'efectivo'])
        ->assertUnprocessable();
});

it('automatically cancels an apartado past its fecha_limite, returning the artículo to the tienda', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'reservada', 'precio_venta' => 600]);

    $venta = Venta::factory()->paraArticulo($bien)->create([
        'cliente_id' => $cliente->id, 'vendido_por' => $this->admin->id,
        'forma_venta' => 'apartado', 'estado' => 'activa',
        'precio_venta' => 600, 'inicial' => 100, 'saldo_pendiente' => 500,
        'fecha_limite' => now()->subDay(),
    ]);

    $cancelados = app(VentaService::class)->cancelarVencidas();

    expect($cancelados)->toBe(1);
    expect($venta->fresh()->estado)->toBe('cancelada')
        ->and($bien->fresh()->estado)->toBe('disponible_venta');
});

it('allows an admin to manually cancel an active apartado', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'reservada', 'precio_venta' => 600]);

    $venta = Venta::factory()->paraArticulo($bien)->create([
        'cliente_id' => $cliente->id, 'vendido_por' => $this->admin->id,
        'forma_venta' => 'apartado', 'estado' => 'activa',
        'precio_venta' => 600, 'inicial' => 100, 'saldo_pendiente' => 500,
        'fecha_limite' => now()->addDays(10),
    ]);

    $this->postJson("/api/ventas/{$venta->id}/cancelar")->assertSuccessful()->assertJsonPath('data.estado', 'cancelada');

    expect($bien->fresh()->estado)->toBe('disponible_venta');
});
