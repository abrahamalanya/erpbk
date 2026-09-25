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
    ConfiguracionVenta::factory()->deEmpresa($this->empresa)->create(['interes_mensual_default' => 5]);

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');

    $caja = Caja::factory()->create(['user_id' => $this->admin->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 1000]);
});

it('renders the cronograma PDF for a venta a crédito', function () {
    $venta = Venta::findOrFail(
        $this->postJson('/api/ventas', [
            'tipo' => 'bien',
            'articulo_id' => $this->bien->id,
            'cliente_id' => $this->cliente->id,
            'forma_venta' => 'credito',
            'inicial' => 200,
            'numero_cuotas' => 4,
            'tipo_cuota' => 'mensual',
            'medio' => 'efectivo',
        ])->assertCreated()->json('data.id')
    );

    $this->get("/api/ventas/{$venta->id}/cronograma/ver")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');
});

it('rejects the cronograma PDF for a contado sale', function () {
    $venta = Venta::findOrFail(
        $this->postJson('/api/ventas', [
            'tipo' => 'bien',
            'articulo_id' => $this->bien->id,
            'cliente_id' => $this->cliente->id,
            'forma_venta' => 'contado',
            'medio' => 'efectivo',
        ])->assertCreated()->json('data.id')
    );

    $this->get("/api/ventas/{$venta->id}/cronograma/ver")->assertNotFound();
});
