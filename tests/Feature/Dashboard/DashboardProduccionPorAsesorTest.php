<?php

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

// cicloDe(), clienteEn(), créditoDe() y desembolsoHoy() viven en tests/Pest.php
// (compartidas con DashboardInicialTest/DashboardGraficoCobranzaDesembolsoTest).

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->otraAgencia = Agencia::factory()->for($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $this->otroAsesor->assignRole('asesor');

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');

    $this->ciclo = cicloDe($this->asesor, $this->empresa, $this->agencia);
});

function filaAsesor(array $filas, int $asesorId): ?array
{
    foreach ($filas as $fila) {
        if ($fila['asesor_id'] === $asesorId) {
            return $fila;
        }
    }

    return null;
}

/** Mueve el created_at de un desembolso ya creado a otra fecha, para probar el filtro de período. */
function moverDesembolsoA(CajaMovimiento $movimiento, string $fecha): void
{
    CajaMovimiento::query()->where('id', $movimiento->id)->update(['created_at' => $fecha]);
}

it('groups desembolsos by asesor, with the 4 tipos filled within each asesor', function () {
    $cliente = clienteEn($this->agencia);

    desembolsoHoy(créditoDe($cliente, 'prendario'), $this->asesor, '500.00');
    desembolsoHoy(créditoDe($cliente, 'hipotecario'), $this->asesor, '300.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = $this->getJson('/api/dashboard/produccion-por-asesor')->assertSuccessful()->json('data.filas');

    expect($filas)->toHaveCount(1);
    $fila = filaAsesor($filas, $this->asesor->id);

    assertTotal(800.00, $fila['total']);
    expect($fila['cantidad'])->toBe(2)
        ->and($fila['tipos'])->toHaveCount(4)
        ->and(array_column($fila['tipos'], 'tipo_credito'))->toEqual(['prendario', 'vehicular', 'hipotecario', 'diario']);

    assertTotal(500.00, filaTipo($fila['tipos'], 'prendario')['monto']);
    assertTotal(300.00, filaTipo($fila['tipos'], 'hipotecario')['monto']);
    assertTotal(0.00, filaTipo($fila['tipos'], 'vehicular')['monto']);
});

it('orders asesores by total production descending', function () {
    $cliente = clienteEn($this->agencia);

    desembolsoHoy(créditoDe($cliente), $this->asesor, '200.00');
    desembolsoHoy(créditoDe($cliente), $this->otroAsesor, '900.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = $this->getJson('/api/dashboard/produccion-por-asesor')->assertSuccessful()->json('data.filas');

    expect($filas)->toHaveCount(2)
        ->and($filas[0]['asesor_id'])->toBe($this->otroAsesor->id)
        ->and($filas[1]['asesor_id'])->toBe($this->asesor->id);
});

it('does not list an asesor with no production in the period', function () {
    desembolsoHoy(créditoDe(clienteEn($this->agencia)), $this->asesor, '200.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = $this->getJson('/api/dashboard/produccion-por-asesor')->assertSuccessful()->json('data.filas');

    expect($filas)->toHaveCount(1)
        ->and(filaAsesor($filas, $this->otroAsesor->id))->toBeNull();
});

it('excludes a credito that was approved but never desembolsado', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);
    $credito->update(['estado' => 'aprobado']);

    Sanctum::actingAs($this->admin, ['*']);

    $filas = $this->getJson('/api/dashboard/produccion-por-asesor')->assertSuccessful()->json('data.filas');

    expect($filas)->toBe([]);
});

it('filters by agencia, asesor and tipo_credito', function () {
    $clienteA = clienteEn($this->agencia);
    $clienteB = clienteEn($this->otraAgencia);

    desembolsoHoy(créditoDe($clienteA, 'prendario'), $this->asesor, '100.00');
    desembolsoHoy(créditoDe($clienteA, 'hipotecario'), $this->otroAsesor, '400.00');

    $otroAsesorOtraAgencia = User::factory()->forAgencia($this->otraAgencia)->create();
    $otroAsesorOtraAgencia->assignRole('asesor');
    $otroCiclo = cicloDe($otroAsesorOtraAgencia, $this->empresa, $this->otraAgencia);
    test()->ciclo = $otroCiclo;
    desembolsoHoy(créditoDe($clienteB), $otroAsesorOtraAgencia, '900.00');

    Sanctum::actingAs($this->admin, ['*']);

    $porAgencia = $this->getJson("/api/dashboard/produccion-por-asesor?agencia_id={$this->agencia->id}")
        ->assertSuccessful()->json('data.filas');
    expect($porAgencia)->toHaveCount(2)
        ->and(filaAsesor($porAgencia, $otroAsesorOtraAgencia->id))->toBeNull();

    $porAsesor = $this->getJson("/api/dashboard/produccion-por-asesor?asesor_id={$this->asesor->id}")
        ->assertSuccessful()->json('data.filas');
    expect($porAsesor)->toHaveCount(1);
    assertTotal(100.00, $porAsesor[0]['total']);

    $porTipo = $this->getJson('/api/dashboard/produccion-por-asesor?tipo_credito=hipotecario')
        ->assertSuccessful()->json('data.filas');
    expect($porTipo)->toHaveCount(1);
    assertTotal(400.00, filaAsesor($porTipo, $this->otroAsesor->id)['total']);
});

it('filters by desde/hasta, defaulting to the current month', function () {
    $cliente = clienteEn($this->agencia);

    $deHoy = desembolsoHoy(créditoDe($cliente), $this->asesor, '100.00');
    $delMesPasado = desembolsoHoy(créditoDe($cliente), $this->asesor, '900.00');
    moverDesembolsoA($delMesPasado, now()->subMonths(2)->toDateString());

    Sanctum::actingAs($this->admin, ['*']);

    $porDefecto = $this->getJson('/api/dashboard/produccion-por-asesor')->assertSuccessful()->json('data.filas');
    assertTotal(100.00, $porDefecto[0]['total']);

    $rangoAmplio = $this->getJson('/api/dashboard/produccion-por-asesor?desde='.now()->subMonths(3)->toDateString())
        ->assertSuccessful()->json('data.filas');
    assertTotal(1000.00, $rangoAmplio[0]['total']);
});

it('does not let an asesor see another asesor production', function () {
    // credito.registrado_por decide la VISIBILIDAD (CreditoHierarchyService),
    // CajaMovimiento.registrado_por decide la ATRIBUCIÓN de a quién se le
    // suma la producción — acá tienen que coincidir para que el propio
    // asesor pueda ver su desembolso.
    $cliente = clienteEn($this->agencia);

    desembolsoHoy(créditoDe($cliente, registradoPor: $this->asesor), $this->asesor, '100.00');
    desembolsoHoy(créditoDe($cliente, registradoPor: $this->otroAsesor), $this->otroAsesor, '900.00');

    Sanctum::actingAs($this->asesor, ['*']);

    $filas = $this->getJson('/api/dashboard/produccion-por-asesor')->assertSuccessful()->json('data.filas');

    expect($filas)->toHaveCount(1);
    assertTotal(100.00, $filas[0]['total']);
});

it('does not let an administrador_agencia see production of another agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    $otroAsesorOtraAgencia = User::factory()->forAgencia($this->otraAgencia)->create();
    $otroAsesorOtraAgencia->assignRole('asesor');
    test()->ciclo = cicloDe($otroAsesorOtraAgencia, $this->empresa, $this->otraAgencia);
    desembolsoHoy(créditoDe(clienteEn($this->otraAgencia)), $otroAsesorOtraAgencia, '900.00');

    Sanctum::actingAs($adminAgencia, ['*']);

    expect($this->getJson('/api/dashboard/produccion-por-asesor')->assertSuccessful()->json('data.filas'))->toBe([]);
});

it('denies access without the dashboard.ver permission', function () {
    $sinRol = User::factory()->forEmpresa($this->empresa)->create();

    Sanctum::actingAs($sinRol, ['*']);

    $this->getJson('/api/dashboard/produccion-por-asesor')->assertForbidden();
});

it('rejects an invalid tipo_credito and an invalid date range', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/dashboard/produccion-por-asesor?tipo_credito=ventas')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tipo_credito');

    $this->getJson('/api/dashboard/produccion-por-asesor?desde='.now()->toDateString().'&hasta='.now()->subDay()->toDateString())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hasta');
});
