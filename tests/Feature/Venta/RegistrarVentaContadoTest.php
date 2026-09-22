<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\Venta;
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

it('registers a contado sale and immediately marks it as pagada', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 800]);

    $response = $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'contado',
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('pagada')
        ->and($response->json('data.precio_venta'))->toBe('800.00')
        ->and($response->json('data.inicial'))->toBe('800.00')
        ->and($response->json('data.saldo_pendiente'))->toBe('0.00');

    expect($bien->fresh()->estado)->toBe('vendida');

    $venta = Venta::findOrFail($response->json('data.id'));
    expect($venta->pagos)->toHaveCount(1)
        ->and($venta->documentos->pluck('tipo')->all())->toBe(['voucher']);

    $movimiento = $venta->pagos->first()->cajaMovimiento;
    expect($movimiento)->not->toBeNull()
        ->and($movimiento->venta_id)->toBe($venta->id)
        ->and($movimiento->tipo)->toBe('ingreso');
});

it('sells the precio_oferta instead of precio_venta when one is set', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 800, 'precio_oferta' => 650]);

    $response = $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'contado',
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.precio_venta'))->toBe('650.00');
});

it('also generates the compra_venta document when the artículo is a vehículo', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $vehiculo = Vehiculo::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 12000]);

    $response = $this->postJson('/api/ventas', [
        'tipo' => 'vehiculo',
        'articulo_id' => $vehiculo->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'contado',
        'medio' => 'transferencia',
    ])->assertCreated();

    $venta = Venta::findOrFail($response->json('data.id'));
    expect($venta->documentos->pluck('tipo')->all())->toBe(['voucher', 'compra_venta']);
});

it('rejects a sale when the artículo is not disponible_venta', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia', 'precio_venta' => 800]);

    $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'contado',
        'medio' => 'efectivo',
    ])->assertNotFound();
});

it('rejects registering a sale without an open caja', function () {
    $otroAdmin = User::factory()->forAgencia($this->agencia)->create();
    $otroAdmin->assignRole('administrador_agencia');
    Sanctum::actingAs($otroAdmin, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 800]);

    $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'contado',
        'medio' => 'efectivo',
    ])->assertUnprocessable();
});

it('allows an asesor with ventas.crear to register a sale from their own caja', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');

    $cajaAsesor = Caja::factory()->create(['user_id' => $asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $cajaAsesor->id, 'empresa_id' => $cajaAsesor->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);

    Sanctum::actingAs($asesor, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 800]);

    $response = $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'contado',
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.vendido_por'))->toBe($asesor->id);
});

it('denies a role without ventas.crear (e.g. peinadora) from registering a sale', function () {
    $peinadora = User::factory()->forAgencia($this->agencia)->create();
    $peinadora->assignRole('peinadora');
    Sanctum::actingAs($peinadora, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 800]);

    $this->postJson('/api/ventas', [
        'tipo' => 'bien',
        'articulo_id' => $bien->id,
        'cliente_id' => $cliente->id,
        'forma_venta' => 'contado',
        'medio' => 'efectivo',
    ])->assertForbidden();
});
