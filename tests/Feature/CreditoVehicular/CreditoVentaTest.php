<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create(['razon_social' => 'CREDIMAS ORIENTE E.I.R.L.', 'ruc' => '20602137903']);
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'vehicular',
        'interes_default' => 12, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'prendario',
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 50000, 'abierta_at' => now(),
    ]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->vehiculo = Vehiculo::factory()->paraCliente($this->cliente)->create(['valorizacion' => 20000]);
});

function creditoVehicularEnVenta($test): Credito
{
    return Credito::factory()->paraVehiculo($test->vehiculo)
        ->vencido(diasVencido: 40)
        ->create([
            'registrado_por' => $test->asesor->id,
            'supervisado_por' => $test->adminAgencia->id,
            'empresa_id' => $test->empresa->id,
            'agencia_id' => $test->agencia->id,
            'estado' => 'en_venta',
            'conformidad_confirmada_at' => now(),
        ]);
}

it('lets an admin close an en_venta vehicular crédito by registering the comprador and generating the contrato de transferencia', function () {
    $credito = creditoVehicularEnVenta($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $response = $this->postJson("/api/creditos-prendarios/{$credito->id}/vender", [
        'comprador_nombre' => 'Carlos Manuel Lopez Castro',
        'comprador_tipo_documento' => 'dni',
        'comprador_numero_documento' => '42035904',
        'comprador_domicilio' => 'Jr. Grau S/N',
        'precio_transferencia' => 3300,
        'pagos_previos' => [
            ['monto' => 2000, 'fecha' => now()->subDays(10)->toDateString()],
            ['monto' => 900, 'fecha' => now()->subDays(3)->toDateString()],
        ],
    ])->assertSuccessful();

    expect($response->json('data.estado'))->toBe('vendido');

    $credito = $credito->fresh();
    expect($credito->estado)->toBe('vendido');

    $doc = $credito->documentos()->where('tipo', 'contrato_transferencia')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->datos['comprador_nombre'])->toBe('Carlos Manuel Lopez Castro')
        ->and($doc->datos['precio_transferencia'])->toBe(3300);

    // El vehículo ya no está atado a un crédito sin resolver: vuelve a disponible.
    expect(Vehiculo::disponibles()->whereKey($this->vehiculo->id)->exists())->toBeTrue();

    $this->get("/api/creditos-prendarios/{$credito->id}/documentos/{$doc->id}/ver")
        ->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('fills the contrato de transferencia PDF with comprador, precio and saldo', function () {
    $credito = creditoVehicularEnVenta($this)->load(['cliente', 'agencia', 'empresa']);

    $datos = [
        'comprador_nombre' => 'Carlos Manuel Lopez Castro',
        'comprador_tipo_documento' => 'dni',
        'comprador_numero_documento' => '42035904',
        'comprador_domicilio' => 'Jr. Grau S/N',
        'precio_transferencia' => '3300.00',
        'pagos_previos' => [
            ['monto' => '2900.00', 'fecha' => now()->subDays(5)->toDateString()],
        ],
    ];

    $doc = $credito->documentos()->create([
        'empresa_id' => $credito->empresa_id, 'tipo' => 'contrato_transferencia',
        'datos' => $datos, 'generado_por' => $this->adminAgencia->id, 'generado_at' => now(),
    ]);

    $garantias = $credito->vehiculos()->get();

    $html = view('modules.credito-vehicular.documentos.contrato_transferencia', [
        'credito' => $credito, 'documento' => $doc, 'garantias' => $garantias, 'datos' => $datos,
        'fotoDataUri' => fn () => null,
    ])->render();

    expect($html)->toContain('CARLOS MANUEL LOPEZ CASTRO')
        ->and($html)->toContain('42035904')
        ->and($html)->toContain(strtoupper($this->vehiculo->placa))
        ->and($html)->toContain('3,300.00')
        ->and($html)->toContain('400.00') // saldo = 3300 - 2900
        ->and($html)->toContain('CREDIMAS ORIENTE E.I.R.L.');
});

it('rejects vender on a crédito that is not en_venta', function () {
    $credito = Credito::factory()->paraVehiculo($this->vehiculo)->activo()
        ->create([
            'registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        ]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/vender", [
        'comprador_nombre' => 'Carlos Lopez', 'comprador_tipo_documento' => 'dni',
        'comprador_numero_documento' => '42035904', 'precio_transferencia' => 3300,
    ])->assertUnprocessable();
});

it('rejects vender on a prendario crédito even when en_venta', function () {
    $bien = Bien::factory()->paraCliente($this->cliente)->create();
    $credito = Credito::factory()->paraBien($bien)
        ->create([
            'registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
            'estado' => 'en_venta',
        ]);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/vender", [
        'comprador_nombre' => 'Carlos Lopez', 'comprador_tipo_documento' => 'dni',
        'comprador_numero_documento' => '42035904', 'precio_transferencia' => 1000,
    ])->assertUnprocessable();
});

it('rejects pagos_previos that add up to more than the precio_transferencia', function () {
    $credito = creditoVehicularEnVenta($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/vender", [
        'comprador_nombre' => 'Carlos Lopez', 'comprador_tipo_documento' => 'dni',
        'comprador_numero_documento' => '42035904', 'precio_transferencia' => 1000,
        'pagos_previos' => [['monto' => 1500, 'fecha' => now()->toDateString()]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['pagos_previos']);
});

it('denies asesor from closing the venta', function () {
    $credito = creditoVehicularEnVenta($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/vender", [
        'comprador_nombre' => 'Carlos Lopez', 'comprador_tipo_documento' => 'dni',
        'comprador_numero_documento' => '42035904', 'precio_transferencia' => 1000,
    ])->assertForbidden();
});
