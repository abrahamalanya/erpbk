<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->otraAgencia = Agencia::factory()->for($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
});

/** Cliente de $asesor con un crédito activo y una cuota vencida hoy (o en la fecha indicada). */
function clienteConCuotaDe(User $asesor, ?string $fechaVencimiento = null): Cliente
{
    $cliente = Cliente::factory()->asignadoA($asesor)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create();
    $credito = Credito::factory()->paraBien($bien)->create([
        'estado' => 'activo',
        'agencia_id' => $cliente->agencia_id,
        'empresa_id' => $cliente->empresa_id,
    ]);

    CuotaCredito::factory()->paraCredito($credito)->create([
        'fecha_vencimiento' => $fechaVencimiento ?? now()->toDateString(),
        'monto_total' => '100.00',
        'monto_abonado' => '0.00',
    ]);

    return $cliente;
}

/** Registra un cobro de hoy sobre un crédito del cliente (lo crea si no viene uno). */
function cobroHoyDe(Cliente $cliente, ?Credito $credito = null, string $estado = 'registrado'): Cobro
{
    $credito ??= Credito::factory()->create([
        'cliente_id' => $cliente->id,
        'agencia_id' => $cliente->agencia_id,
        'empresa_id' => $cliente->empresa_id,
        'estado' => 'activo',
    ]);

    return Cobro::factory()->create([
        'cliente_id' => $cliente->id,
        'credito_id' => $credito->id,
        'empresa_id' => $cliente->empresa_id,
        'estado' => $estado,
    ]);
}

function filaAsesorClientes(array $filas, int $asesorId): ?array
{
    foreach ($filas as $fila) {
        if ($fila['asesor_id'] === $asesorId) {
            return $fila;
        }
    }

    return null;
}

it('counts total clientes, a_cobrar and cobrados independently', function () {
    $conCuotaYCobro = clienteConCuotaDe($this->asesor);
    cobroHoyDe($conCuotaYCobro);

    clienteConCuotaDe($this->asesor); // a_cobrar sin cobrar aún
    Cliente::factory()->asignadoA($this->asesor)->create(); // en el roster, sin nada programado hoy

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorClientes(
        $this->getJson('/api/dashboard/avance-clientes-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    expect($fila)
        ->not->toBeNull()
        ->and($fila['total_clientes'])->toBe(3)
        ->and($fila['a_cobrar'])->toBe(2)
        ->and($fila['cobrados'])->toBe(1)
        ->and($fila['pendientes'])->toBe(1);
    assertTotal(50.0, $fila['porcentaje']);
});

it('counts a client once even with two creditos due today', function () {
    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();

    foreach ([1, 2] as $_) {
        $bien = Bien::factory()->paraCliente($cliente)->create();
        $credito = Credito::factory()->paraBien($bien)->create([
            'estado' => 'activo',
            'agencia_id' => $cliente->agencia_id,
            'empresa_id' => $cliente->empresa_id,
        ]);
        CuotaCredito::factory()->paraCredito($credito)->create([
            'fecha_vencimiento' => now()->toDateString(),
            'monto_total' => '100.00',
            'monto_abonado' => '0.00',
        ]);
        cobroHoyDe($cliente, $credito);
    }

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorClientes(
        $this->getJson('/api/dashboard/avance-clientes-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    expect($fila['a_cobrar'])->toBe(1)
        ->and($fila['cobrados'])->toBe(1);
    assertTotal(100.0, $fila['porcentaje']);
});

it('does not count an anulado cobro as cobrado today', function () {
    $cliente = clienteConCuotaDe($this->asesor);
    cobroHoyDe($cliente, estado: 'anulado');

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorClientes(
        $this->getJson('/api/dashboard/avance-clientes-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    expect($fila['cobrados'])->toBe(0);
    assertTotal(0.0, $fila['porcentaje']);
});

it('reports a null porcentaje when there is nothing due today', function () {
    Cliente::factory()->asignadoA($this->asesor)->create();

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorClientes(
        $this->getJson('/api/dashboard/avance-clientes-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    expect($fila['total_clientes'])->toBe(1)
        ->and($fila['a_cobrar'])->toBe(0)
        ->and($fila['porcentaje'])->toBeNull();
});

it('lets an asesor see their own row even though asesoresVisibles() would otherwise return empty for that role', function () {
    clienteConCuotaDe($this->asesor);

    Sanctum::actingAs($this->asesor, ['*']);

    $filas = $this->getJson('/api/dashboard/avance-clientes-hoy')->assertSuccessful()->json('data.filas');

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['a_cobrar'])->toBe(1);
});

it('does not let an administrador_agencia see avance of another agencia', function () {
    $asesorOtraAgencia = User::factory()->forAgencia($this->otraAgencia)->create();
    $asesorOtraAgencia->assignRole('asesor');
    clienteConCuotaDe($asesorOtraAgencia);

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($adminAgencia, ['*']);

    $filas = $this->getJson('/api/dashboard/avance-clientes-hoy')->assertSuccessful()->json('data.filas');

    expect(filaAsesorClientes($filas, $asesorOtraAgencia->id))->toBeNull();
});

it('denies the summary without the dashboard.ver permission', function () {
    $sinRol = User::factory()->forEmpresa($this->empresa)->create();

    Sanctum::actingAs($sinRol, ['*']);

    $this->getJson('/api/dashboard/avance-clientes-hoy')->assertForbidden();
});

it('returns, for each cliente cobrado today, the credito and the abono of every cobro', function () {
    $cliente = clienteConCuotaDe($this->asesor);
    $cobro = cobroHoyDe($cliente);

    Sanctum::actingAs($this->admin, ['*']);

    $detalle = $this->getJson("/api/dashboard/clientes-cobrados-hoy?asesor_id={$this->asesor->id}")
        ->assertSuccessful()
        ->json('data');

    expect($detalle)->toHaveCount(1);
    $fila = $detalle[0];

    expect($fila['cliente_id'])->toBe($cliente->id)
        ->and($fila['cobros'])->toHaveCount(1)
        ->and($fila['cobros'][0]['cobro_id'])->toBe($cobro->id)
        ->and($fila['cobros'][0]['credito_id'])->toBe($cobro->credito_id);
    assertTotal((float) $cobro->monto_pagado, $fila['total_cobrado']);
    assertTotal((float) $cobro->monto_pagado, $fila['cobros'][0]['monto_pagado']);
});

it('groups several cobros of the same cliente today under one entry', function () {
    $cliente = clienteConCuotaDe($this->asesor);
    cobroHoyDe($cliente);
    cobroHoyDe($cliente);

    Sanctum::actingAs($this->admin, ['*']);

    $detalle = $this->getJson("/api/dashboard/clientes-cobrados-hoy?asesor_id={$this->asesor->id}")
        ->assertSuccessful()
        ->json('data');

    expect($detalle)->toHaveCount(1)
        ->and($detalle[0]['cobros'])->toHaveCount(2);
});

it('excludes cobros of clientes from another asesor', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    cobroHoyDe(clienteConCuotaDe($otroAsesor));

    Sanctum::actingAs($this->admin, ['*']);

    $detalle = $this->getJson("/api/dashboard/clientes-cobrados-hoy?asesor_id={$this->asesor->id}")
        ->assertSuccessful()
        ->json('data');

    expect($detalle)->toBeEmpty();
});

it('denies the detail of an asesor outside the actor scope', function () {
    $asesorOtraAgencia = User::factory()->forAgencia($this->otraAgencia)->create();
    $asesorOtraAgencia->assignRole('asesor');

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($adminAgencia, ['*']);

    $this->getJson("/api/dashboard/clientes-cobrados-hoy?asesor_id={$asesorOtraAgencia->id}")->assertForbidden();
});

it('requires asesor_id for the detail endpoint', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/dashboard/clientes-cobrados-hoy')->assertUnprocessable();
});

it('denies the detail without the dashboard.ver permission', function () {
    $sinRol = User::factory()->forEmpresa($this->empresa)->create();

    Sanctum::actingAs($sinRol, ['*']);

    $this->getJson("/api/dashboard/clientes-cobrados-hoy?asesor_id={$this->asesor->id}")->assertForbidden();
});
