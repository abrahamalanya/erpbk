<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\ConfiguracionVenta;
use App\Modules\Venta\Models\Venta;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionVenta::factory()->deEmpresa($this->empresa)->create(['interes_mensual_default' => 0]);

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');

    $caja = Caja::factory()->create(['user_id' => $this->admin->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 600]);

    $this->venta = Venta::findOrFail(
        $this->postJson('/api/ventas', [
            'tipo' => 'bien',
            'articulo_id' => $this->bien->id,
            'cliente_id' => $this->cliente->id,
            'forma_venta' => 'credito',
            'inicial' => 60,
            'numero_cuotas' => 3,
            'tipo_cuota' => 'mensual',
            'medio' => 'efectivo',
        ])->assertCreated()->json('data.id')
    );
});

it('pays a cuota in full and keeps the sale activa while cuotas remain', function () {
    $cuota = $this->venta->cuotas()->where('numero_cuota', 1)->firstOrFail();

    $this->postJson("/api/ventas/{$this->venta->id}/cuotas/{$cuota->id}/pagar", ['monto' => 180, 'medio' => 'efectivo'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'activa')
        ->assertJsonPath('data.saldo_pendiente', '360.00');

    expect($cuota->fresh()->estado)->toBe('pagada');
});

it('marks the sale as pagada and generates the voucher once every cuota is fully paid', function () {
    foreach ($this->venta->cuotas as $cuota) {
        $this->postJson("/api/ventas/{$this->venta->id}/cuotas/{$cuota->id}/pagar", ['monto' => 180, 'medio' => 'efectivo'])
            ->assertSuccessful();
    }

    $venta = $this->venta->fresh(['documentos']);
    expect($venta->estado)->toBe('pagada')
        ->and($venta->saldo_pendiente)->toBe('0.00')
        ->and($this->bien->fresh()->estado)->toBe('vendida')
        ->and($venta->documentos->pluck('tipo')->all())->toBe(['contrato_credito', 'voucher']);
});

it('accepts a partial abono on a cuota without marking it pagada', function () {
    $cuota = $this->venta->cuotas()->where('numero_cuota', 1)->firstOrFail();

    $this->postJson("/api/ventas/{$this->venta->id}/cuotas/{$cuota->id}/pagar", ['monto' => 120, 'medio' => 'efectivo'])
        ->assertSuccessful();

    expect($cuota->fresh()->estado)->toBe('pendiente')
        ->and($cuota->fresh()->monto_abonado)->toBe('120.00');
});

it('rejects paying a cuota more than what is pending on it', function () {
    $cuota = $this->venta->cuotas()->where('numero_cuota', 1)->firstOrFail();

    $this->postJson("/api/ventas/{$this->venta->id}/cuotas/{$cuota->id}/pagar", ['monto' => 500, 'medio' => 'efectivo'])
        ->assertUnprocessable();
});

it('renders the voucher PDF once the sale is pagada', function () {
    foreach ($this->venta->cuotas as $cuota) {
        $this->postJson("/api/ventas/{$this->venta->id}/cuotas/{$cuota->id}/pagar", ['monto' => 180, 'medio' => 'efectivo'])
            ->assertSuccessful();
    }

    $venta = $this->venta->fresh(['documentos']);
    $voucher = $venta->documentos->firstWhere('tipo', 'voucher');

    $this->get("/api/ventas/{$venta->id}/documentos/{$voucher->id}")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});
