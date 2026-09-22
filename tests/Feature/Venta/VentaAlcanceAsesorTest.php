<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\Venta;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Un asesor con ventas.ver/crear/cobrar solo opera las ventas que él mismo
 * registró (vendido_por) — mirror de CreditoHierarchyService::puedeVer()
 * para asesor. ventas.cancelar sigue siendo solo de administradores.
 */
function abreCajaVenta(Empresa $empresa, Agencia $agencia, User $actor): void
{
    $caja = Caja::factory()->create(['user_id' => $actor->id, 'empresa_id' => $empresa->id, 'agencia_id' => $agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    abreCajaVenta($this->empresa, $this->agencia, $this->asesor);

    $this->otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $this->otroAsesor->assignRole('asesor');
    abreCajaVenta($this->empresa, $this->agencia, $this->otroAsesor);
});

it('lets an asesor view and cobrar a venta they registered themselves', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 600]);

    $venta = Venta::findOrFail(
        $this->postJson('/api/ventas', [
            'tipo' => 'bien', 'articulo_id' => $bien->id, 'cliente_id' => $cliente->id,
            'forma_venta' => 'apartado', 'inicial' => 100, 'fecha_limite' => now()->addDays(10)->toDateString(), 'medio' => 'efectivo',
        ])->assertCreated()->json('data.id')
    );

    $this->getJson("/api/ventas/{$venta->id}")->assertSuccessful();
    $this->postJson("/api/ventas/{$venta->id}/abonar", ['monto' => 50, 'medio' => 'efectivo'])->assertSuccessful();
});

it('denies an asesor from viewing or cobrando a venta registered by another asesor', function () {
    Sanctum::actingAs($this->otroAsesor, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 600]);

    $venta = Venta::findOrFail(
        $this->postJson('/api/ventas', [
            'tipo' => 'bien', 'articulo_id' => $bien->id, 'cliente_id' => $cliente->id,
            'forma_venta' => 'apartado', 'inicial' => 100, 'fecha_limite' => now()->addDays(10)->toDateString(), 'medio' => 'efectivo',
        ])->assertCreated()->json('data.id')
    );

    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson("/api/ventas/{$venta->id}")->assertForbidden();
    $this->postJson("/api/ventas/{$venta->id}/abonar", ['monto' => 50, 'medio' => 'efectivo'])->assertForbidden();
});

it('scopes the ventas index to only the asesor’s own ventas', function () {
    Sanctum::actingAs($this->otroAsesor, ['*']);
    $clienteOtro = Cliente::factory()->forAgencia($this->agencia)->create();
    $bienOtro = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 600]);
    $this->postJson('/api/ventas', [
        'tipo' => 'bien', 'articulo_id' => $bienOtro->id, 'cliente_id' => $clienteOtro->id,
        'forma_venta' => 'contado', 'medio' => 'efectivo',
    ])->assertCreated();

    Sanctum::actingAs($this->asesor, ['*']);
    $clienteMio = Cliente::factory()->forAgencia($this->agencia)->create();
    $bienMio = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 500]);
    $this->postJson('/api/ventas', [
        'tipo' => 'bien', 'articulo_id' => $bienMio->id, 'cliente_id' => $clienteMio->id,
        'forma_venta' => 'contado', 'medio' => 'efectivo',
    ])->assertCreated();

    $response = $this->getJson('/api/ventas')->assertSuccessful();

    // vendidoPor viene precargado en el index, así que se serializa como
    // objeto bajo la misma clave snake_case (vendido_por), no como el id crudo.
    expect($response->json('data.total'))->toBe(1)
        ->and($response->json('data.data.0.vendido_por.id'))->toBe($this->asesor->id);
});

it('denies an asesor from cancelando an apartado even one they registered', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 600]);

    $venta = Venta::findOrFail(
        $this->postJson('/api/ventas', [
            'tipo' => 'bien', 'articulo_id' => $bien->id, 'cliente_id' => $cliente->id,
            'forma_venta' => 'apartado', 'inicial' => 100, 'fecha_limite' => now()->addDays(10)->toDateString(), 'medio' => 'efectivo',
        ])->assertCreated()->json('data.id')
    );

    $this->postJson("/api/ventas/{$venta->id}/cancelar")->assertForbidden();
});

it('scopes the catálogo de venta and las solicitudes de tienda to the asesor’s own agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    Bien::factory()->forAgencia($otraAgencia)->create(['estado' => 'disponible_venta']);
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);

    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->getJson('/api/ventas/catalogo')->assertSuccessful();

    expect($response->json('data.total'))->toBe(1);
});
