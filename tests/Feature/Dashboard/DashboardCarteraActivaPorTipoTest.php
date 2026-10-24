<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

// clienteEn(), creditoCarteraDe() y créditoDe() viven en tests/Pest.php
// (compartidas con DashboardInicialTest).

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

// filaTipo() vive en tests/Pest.php (compartida con DashboardProduccionPorAsesorTest).

it('always returns the 4 tipos, zero-filled when there is no cartera', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $filas = $this->getJson('/api/dashboard/cartera-activa-por-tipo')->assertSuccessful()->json('data.filas');

    expect($filas)->toHaveCount(4)
        ->and(array_column($filas, 'tipo_credito'))->toEqual(['prendario', 'vehicular', 'hipotecario', 'diario']);

    foreach ($filas as $fila) {
        assertTotal(0.00, $fila['saldo']);
        expect($fila['cantidad'])->toBe(0);
    }
});

it('sums the pending saldo of activo and vencido creditos, grouped by tipo', function () {
    $cliente = clienteEn($this->agencia);

    creditoCarteraDe($cliente, 'activo', '1000.00', '600.00', 'prendario'); // saldo 400
    creditoCarteraDe($cliente, 'vencido', '500.00', '0.00', 'prendario'); // saldo 500
    creditoCarteraDe($cliente, 'vencido', '300.00', '100.00', 'hipotecario'); // saldo 200

    Sanctum::actingAs($this->admin, ['*']);

    $filas = $this->getJson('/api/dashboard/cartera-activa-por-tipo')->assertSuccessful()->json('data.filas');

    assertTotal(900.00, filaTipo($filas, 'prendario')['saldo']);
    expect(filaTipo($filas, 'prendario')['cantidad'])->toBe(2);

    assertTotal(200.00, filaTipo($filas, 'hipotecario')['saldo']);
    assertTotal(0.00, filaTipo($filas, 'vehicular')['saldo']);
    assertTotal(0.00, filaTipo($filas, 'diario')['saldo']);
});

it('excludes liquidado and pendiente creditos from the cartera activa', function () {
    $cliente = clienteEn($this->agencia);

    creditoCarteraDe($cliente, 'activo', '500.00', '0.00');
    creditoCarteraDe($cliente, 'liquidado', '500.00', '500.00');
    créditoDe($cliente); // default factory state: 'pendiente', nunca desembolsado

    Sanctum::actingAs($this->admin, ['*']);

    $filas = $this->getJson('/api/dashboard/cartera-activa-por-tipo')->assertSuccessful()->json('data.filas');

    assertTotal(500.00, filaTipo($filas, 'prendario')['saldo']);
    expect(filaTipo($filas, 'prendario')['cantidad'])->toBe(1);
});

it('never reports a negative saldo for an overpaid credito', function () {
    $cliente = clienteEn($this->agencia);

    creditoCarteraDe($cliente, 'activo', '100.00', '150.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(0.00, filaTipo(
        $this->getJson('/api/dashboard/cartera-activa-por-tipo')->assertSuccessful()->json('data.filas'),
        'prendario',
    )['saldo']);
});

it('filters by agencia', function () {
    creditoCarteraDe(clienteEn($this->agencia), 'activo', '400.00', '0.00');
    creditoCarteraDe(clienteEn($this->otraAgencia), 'activo', '900.00', '0.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(400.00, filaTipo(
        $this->getJson("/api/dashboard/cartera-activa-por-tipo?agencia_id={$this->agencia->id}")
            ->assertSuccessful()->json('data.filas'),
        'prendario',
    )['saldo']);
});

it('filters by asesor via cliente.asesor_id, not credito.registrado_por', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    $clientePropio = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'empresa_id' => $this->empresa->id,
    ]);
    $clienteAjeno = Cliente::factory()->forAgencia($this->agencia)->asignadoA($otroAsesor)->create([
        'empresa_id' => $this->empresa->id,
    ]);

    creditoCarteraDe($clientePropio, 'activo', '400.00', '0.00');
    creditoCarteraDe($clienteAjeno, 'activo', '900.00', '0.00');

    Sanctum::actingAs($this->admin, ['*']);

    assertTotal(400.00, filaTipo(
        $this->getJson("/api/dashboard/cartera-activa-por-tipo?asesor_id={$this->asesor->id}")
            ->assertSuccessful()->json('data.filas'),
        'prendario',
    )['saldo']);
});

it('does not let an administrador_agencia see cartera of another agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    creditoCarteraDe(clienteEn($this->otraAgencia), 'activo', '900.00', '0.00');

    Sanctum::actingAs($adminAgencia, ['*']);

    $filas = $this->getJson('/api/dashboard/cartera-activa-por-tipo')->assertSuccessful()->json('data.filas');

    foreach ($filas as $fila) {
        assertTotal(0.00, $fila['saldo']);
    }
});

it('denies access without the dashboard.ver permission', function () {
    $sinRol = User::factory()->forEmpresa($this->empresa)->create();

    Sanctum::actingAs($sinRol, ['*']);

    $this->getJson('/api/dashboard/cartera-activa-por-tipo')->assertForbidden();
});
