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

it('applies a pago a cuenta on a venta a crédito to its oldest pending cuotas first', function () {
    // 3 cuotas de 180 c/u (sin interés). Un abono de 250 debe cancelar la
    // cuota 1 completa (180) y dejar un adelanto de 70 en la cuota 2.
    $this->postJson("/api/ventas/{$this->venta->id}/abonar", ['monto' => 250, 'medio' => 'efectivo'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'activa')
        ->assertJsonPath('data.saldo_pendiente', '290.00');

    $cuotas = $this->venta->cuotas()->orderBy('numero_cuota')->get();

    expect($cuotas[0]->estado)->toBe('pagada')
        ->and($cuotas[0]->monto_abonado)->toBe('180.00')
        ->and($cuotas[1]->estado)->toBe('pendiente')
        ->and($cuotas[1]->monto_abonado)->toBe('70.00')
        ->and($cuotas[2]->monto_abonado)->toBe('0.00');
});

it('marks the sale as pagada when a pago a cuenta covers every cuota', function () {
    $this->postJson("/api/ventas/{$this->venta->id}/abonar", ['monto' => 540, 'medio' => 'efectivo'])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'pagada')
        ->assertJsonPath('data.saldo_pendiente', '0.00');

    expect($this->venta->cuotas()->where('estado', '!=', 'pagada')->exists())->toBeFalse()
        ->and($this->bien->fresh()->estado)->toBe('vendida');
});

it('rejects a pago a cuenta greater than the saldo pendiente', function () {
    $this->postJson("/api/ventas/{$this->venta->id}/abonar", ['monto' => 1000, 'medio' => 'efectivo'])
        ->assertUnprocessable();
});
