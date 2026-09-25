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
            'numero_cuotas' => 2,
            'tipo_cuota' => 'mensual',
            'medio' => 'efectivo',
        ])->assertCreated()->json('data.id')
    );
});

it('does not flag a venta a crédito as vencida while every cuota is within its due date', function () {
    $this->getJson('/api/ventas')->assertSuccessful()->assertJsonPath('data.data.0.tiene_cuota_vencida', false);
    $this->getJson("/api/ventas/{$this->venta->id}")->assertSuccessful()->assertJsonPath('data.tiene_cuota_vencida', false);
});

it('flags a venta a crédito as vencida once a cuota is past its fecha_vencimiento', function () {
    $this->venta->cuotas()->where('numero_cuota', 1)->update(['fecha_vencimiento' => now()->subDay()]);

    $this->getJson('/api/ventas')->assertSuccessful()->assertJsonPath('data.data.0.tiene_cuota_vencida', true);
    $this->getJson("/api/ventas/{$this->venta->id}")->assertSuccessful()->assertJsonPath('data.tiene_cuota_vencida', true);
});

it('does not flag a venta a crédito as vencida once the overdue cuota is fully paid', function () {
    $cuota = $this->venta->cuotas()->where('numero_cuota', 1)->firstOrFail();
    $cuota->update(['fecha_vencimiento' => now()->subDay()]);

    $this->postJson("/api/ventas/{$this->venta->id}/cuotas/{$cuota->id}/pagar", ['monto' => 270, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $this->getJson("/api/ventas/{$this->venta->id}")->assertSuccessful()->assertJsonPath('data.tiene_cuota_vencida', false);
});
