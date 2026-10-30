<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create(['tipo_credito' => 'prendario']);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create(['tipo_credito' => 'vehicular']);
    $this->cliente1 = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->cliente2 = Cliente::factory()->forAgencia($this->agencia)->create();

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_general');

    Sanctum::actingAs($this->admin, ['*']);
});

it('filters créditos by tipo_credito', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->activo()->create();
    Credito::factory()->paraBien($bien)->vehicular()->activo()->create();

    $response = $this->getJson('/api/creditos-prendarios?tipo_credito=vehicular')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.tipo_credito'))->toBe('vehicular');
});

it('filters créditos by cliente_id', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente1)->create();
    $bien2 = Bien::factory()->paraCliente($this->cliente2)->create();
    Credito::factory()->paraBien($bien1)->activo()->create();
    Credito::factory()->paraBien($bien2)->activo()->create();

    $response = $this->getJson("/api/creditos-prendarios?cliente_id={$this->cliente2->id}")->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.cliente_id'))->toBe($this->cliente2->id);
});

it('filters créditos by estado', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->activo()->create();
    Credito::factory()->paraBien($bien)->vencido()->create();

    $response = $this->getJson('/api/creditos-prendarios?estado=vencido')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.estado'))->toBe('vencido');
});

it('filters créditos by multiple estado values', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->count(2)->create(['estado' => 'pendiente']);
    Credito::factory()->paraBien($bien)->create(['estado' => 'aprobado']);
    Credito::factory()->paraBien($bien)->activo()->create();
    Credito::factory()->paraBien($bien)->vencido()->create();

    $response = $this->getJson('/api/creditos-prendarios?estado[]=aprobado&estado[]=activo&estado[]=vencido')
        ->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(3)
        ->and(collect($response->json('data.data'))->pluck('estado')->all())
        ->each(fn ($estado) => $estado->toBeIn(['aprobado', 'activo', 'vencido']));
});

it('combines tipo_credito, cliente_id and estado filters', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente1)->create();
    $bien2 = Bien::factory()->paraCliente($this->cliente2)->create();
    Credito::factory()->paraBien($bien1)->vencido()->create();
    Credito::factory()->paraBien($bien2)->vencido()->create();
    Credito::factory()->paraBien($bien1)->activo()->create();

    $response = $this->getJson(
        "/api/creditos-prendarios?tipo_credito=prendario&cliente_id={$this->cliente1->id}&estado=vencido"
    )->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1);
});

it('filters créditos by agencia_id', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $clienteOtraAgencia = Cliente::factory()->forAgencia($otraAgencia)->create();
    $bien1 = Bien::factory()->paraCliente($this->cliente1)->create();
    $bien2 = Bien::factory()->paraCliente($clienteOtraAgencia)->create();
    Credito::factory()->paraBien($bien1)->activo()->create(['agencia_id' => $this->agencia->id]);
    Credito::factory()->paraBien($bien2)->activo()->create(['agencia_id' => $otraAgencia->id]);

    $response = $this->getJson("/api/creditos-prendarios?agencia_id={$otraAgencia->id}")->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.agencia_id'))->toBe($otraAgencia->id);
});

it('filters créditos by asesor_id (del cliente)', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $clienteConAsesor = Cliente::factory()->asignadoA($asesor)->create();
    $bien1 = Bien::factory()->paraCliente($this->cliente1)->create();
    $bien2 = Bien::factory()->paraCliente($clienteConAsesor)->create();
    Credito::factory()->paraBien($bien1)->activo()->create();
    Credito::factory()->paraBien($bien2)->activo()->create();

    $response = $this->getJson("/api/creditos-prendarios?asesor_id={$asesor->id}")->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.cliente_id'))->toBe($clienteConAsesor->id)
        ->and($response->json('data.data.0.asesor.id'))->toBe($asesor->id);
});

it('filters créditos by fecha_desembolso range', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->activo()->create(['fecha_desembolso' => now()->subDays(10)->toDateString()]);
    Credito::factory()->paraBien($bien)->activo()->create(['fecha_desembolso' => now()->toDateString()]);

    $response = $this->getJson(
        '/api/creditos-prendarios?fecha_desembolso_desde='.now()->subDays(1)->toDateString()
    )->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and(Carbon::parse($response->json('data.data.0.fecha_desembolso'))->toDateString())->toBe(now()->toDateString());
});

it('finds a credit by full or partial code and combines it with disbursement dates', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    $credito = Credito::factory()->paraBien($bien)->activo()->create(['fecha_desembolso' => '2026-10-20']);
    Credito::factory()->paraBien($bien)->activo()->create(['fecha_desembolso' => '2026-10-21']);
    $codigo = $credito->fresh()->codigo;
    foreach ([$codigo, substr($codigo, 2), ' '.$codigo.' '] as $busqueda) {
        $response = $this->getJson('/api/creditos-prendarios?'.http_build_query([
            'codigo' => $busqueda,
            'fecha_desembolso_desde' => '2026-10-20',
            'fecha_desembolso_hasta' => '2026-10-20',
        ]))->assertSuccessful();
        expect($response->json('data.data'))->toHaveCount(1)
            ->and($response->json('data.data.0.id'))->toBe($credito->id);
    }
    $this->getJson('/api/creditos-prendarios?'.http_build_query([
        'codigo' => $codigo, 'fecha_desembolso_desde' => '2026-10-21',
    ]))->assertSuccessful()->assertJsonCount(0, 'data.data');
});

it('treats code search wildcards as literal characters', function (string $codigo) {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->activo()->create();
    $this->getJson('/api/creditos-prendarios?'.http_build_query(['codigo' => $codigo]))
        ->assertSuccessful()->assertJsonCount(0, 'data.data');
})->with(['%', '_', '!']);

it('validates code and disbursement date filters', function (array $filters) {
    $this->getJson('/api/creditos-prendarios?'.http_build_query($filters))->assertUnprocessable();
})->with([
    [['codigo' => ['C-000001']]],
    [['codigo' => str_repeat('x', 51)]],
    [['fecha_desembolso_desde' => '2026-02-30']],
    [['fecha_desembolso_hasta' => 'invalid']],
    [['fecha_desembolso_desde' => '2026-10-21', 'fecha_desembolso_hasta' => '2026-10-20']],
]);

it('accepts a standalone upper date bound and excludes undisbursed credits', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    $credito = Credito::factory()->paraBien($bien)->activo()->create(['fecha_desembolso' => '2026-10-20']);
    Credito::factory()->paraBien($bien)->create(['fecha_desembolso' => null]);
    Credito::factory()->paraBien($bien)->activo()->create(['fecha_desembolso' => '2026-10-21']);
    $this->getJson('/api/creditos-prendarios?fecha_desembolso_hasta=2026-10-20')->assertSuccessful()
        ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.id', $credito->id);
});

it('does not broaden advisor visibility when searching by code', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    $propio = Credito::factory()->paraBien($bien)->create(['registrado_por' => $asesor->id]);
    $ajeno = Credito::factory()->paraBien($bien)->create(['registrado_por' => $this->admin->id]);
    Sanctum::actingAs($asesor);
    $this->getJson('/api/creditos-prendarios?codigo='.$ajeno->fresh()->codigo)->assertSuccessful()->assertJsonCount(0, 'data.data');
    $this->getJson('/api/creditos-prendarios?codigo='.$propio->fresh()->codigo)->assertSuccessful()->assertJsonPath('data.data.0.id', $propio->id);
});

it('filters créditos by fecha_vencimiento range', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->activo()->create(['fecha_vencimiento' => now()->addDays(5)->toDateString()]);
    Credito::factory()->paraBien($bien)->activo()->create(['fecha_vencimiento' => now()->addDays(60)->toDateString()]);

    $response = $this->getJson(
        '/api/creditos-prendarios?fecha_vencimiento_hasta='.now()->addDays(10)->toDateString()
    )->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and(Carbon::parse($response->json('data.data.0.fecha_vencimiento'))->toDateString())->toBe(now()->addDays(5)->toDateString());
});

it('filters créditos by fecha_solicitud range (created_at del propio crédito)', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    Credito::factory()->paraBien($bien)->activo()->create(['created_at' => now()->subDays(10)]);
    Credito::factory()->paraBien($bien)->activo()->create(['created_at' => now()]);

    $response = $this->getJson(
        '/api/creditos-prendarios?fecha_solicitud_desde='.now()->subDays(1)->toDateString()
    )->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and(Carbon::parse($response->json('data.data.0.created_at'))->toDateString())->toBe(now()->toDateString());
});

it('filters créditos by fecha_cancelacion range usando el cobro de liquidación', function () {
    $bien1 = Bien::factory()->paraCliente($this->cliente1)->create();
    $bien2 = Bien::factory()->paraCliente($this->cliente2)->create();
    $cancelado = Credito::factory()->paraBien($bien1)->activo()->create(['estado' => 'cancelado']);
    Credito::factory()->paraBien($bien2)->activo()->create();

    Cobro::factory()->create([
        'empresa_id' => $cancelado->empresa_id,
        'cliente_id' => $cancelado->cliente_id,
        'credito_id' => $cancelado->id,
        'operacion' => 'liquidacion',
        'created_at' => now()->subDays(2),
    ]);

    $response = $this->getJson(
        '/api/creditos-prendarios?fecha_cancelacion_desde='.now()->subDays(5)->toDateString()
    )->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.id'))->toBe($cancelado->id)
        ->and($response->json('data.data.0.fecha_cancelacion'))->toBe(now()->subDays(2)->toDateString());
});

it('ignores a cobro de liquidación anulado when computing fecha_cancelacion', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    $credito = Credito::factory()->paraBien($bien)->activo()->create();

    Cobro::factory()->create([
        'empresa_id' => $credito->empresa_id,
        'cliente_id' => $credito->cliente_id,
        'credito_id' => $credito->id,
        'operacion' => 'liquidacion',
        'estado' => 'anulado',
        'created_at' => now()->subDay(),
    ]);

    $response = $this->getJson("/api/creditos-prendarios?cliente_id={$this->cliente1->id}")->assertSuccessful();

    expect($response->json('data.data.0.fecha_cancelacion'))->toBeNull();
});

it('computes dias_atraso and saldo from cuotas vencidas impagas', function () {
    $bien = Bien::factory()->paraCliente($this->cliente1)->create();
    // monto_prestamo/interes fijos (no los defaults aleatorios de la factory):
    // el saldo de un crédito activo/vencido de interés simple ya no es un
    // resumen estático de cuotas, sino el mismo cálculo que LIQUIDAR
    // (calcularMontoLiquidacion) — ver CreditoController::index().
    $credito = Credito::factory()->paraBien($bien)->activo()->create(['monto_prestamo' => 1000, 'interes' => 15]);

    CuotaCredito::factory()->paraCredito($credito)->pagada()->create([
        'numero_cuota' => 1,
        'fecha_vencimiento' => now()->subDays(20)->toDateString(),
        'monto_capital' => 200, 'monto_interes' => 30, 'monto_total' => 230, 'monto_abonado' => 230,
    ]);
    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 2,
        'fecha_vencimiento' => now()->subDays(7)->toDateString(),
        'monto_capital' => 150, 'monto_interes' => 0, 'monto_total' => 150, 'monto_abonado' => 0,
    ]);
    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 3,
        'fecha_vencimiento' => now()->addDays(10)->toDateString(),
        'monto_capital' => 150, 'monto_interes' => 0, 'monto_total' => 150, 'monto_abonado' => 0,
    ]);

    $response = $this->getJson("/api/creditos-prendarios?cliente_id={$this->cliente1->id}")->assertSuccessful();

    // total: sigue siendo el resumen estático del cronograma (230+150+150).
    // saldo = calcularMontoLiquidacion(): fecha_desembolso=hoy y
    // dias_minimo_interes=15 ⇒ 15 días cobrados; interés = monto_prestamo *
    // interes * dias_cobrados / 3000 = 1000*15*15/3000 = 75. capital
    // (1000-200 ya pagado) + interés (75-30 ya cubierto=45) + mora (0, el
    // crédito no está vencido) = 800+45 = 845.
    expect($response->json('data.data.0.dias_atraso'))->toBe(7)
        ->and((float) $response->json('data.data.0.total'))->toBe(530.0)
        ->and((float) $response->json('data.data.0.saldo'))->toBe(845.0);
});
