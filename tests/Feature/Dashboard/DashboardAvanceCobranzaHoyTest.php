<?php

use App\Modules\Cliente\Models\Cliente;
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

/** Crédito activo de un cliente ya asignado a $asesor (misma agencia/empresa del cliente). */
function creditoActivoDeAsesor(User $asesor, string $tipo = 'prendario', string $estado = 'activo'): Credito
{
    $cliente = Cliente::factory()->asignadoA($asesor)->create();
    $bien = Bien::factory()->paraCliente($cliente)->create();

    return Credito::factory()->paraBien($bien)->create([
        'tipo_credito' => $tipo,
        'estado' => $estado,
        'agencia_id' => $cliente->agencia_id,
        'empresa_id' => $cliente->empresa_id,
    ]);
}

/** Cuota cuyo vencimiento es exactamente hoy. */
function cuotaHoyDe(Credito $credito, string $montoTotal, string $montoAbonado, int $numeroCuota = 1): CuotaCredito
{
    return CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => $numeroCuota,
        'fecha_vencimiento' => now()->toDateString(),
        'monto_total' => $montoTotal,
        'monto_abonado' => $montoAbonado,
    ]);
}

function filaAsesorAvance(array $filas, int $asesorId): ?array
{
    foreach ($filas as $fila) {
        if ($fila['asesor_id'] === $asesorId) {
            return $fila;
        }
    }

    return null;
}

it('computes programado, cobrado, pendiente and porcentaje', function () {
    $credito = creditoActivoDeAsesor($this->asesor);
    cuotaHoyDe($credito, '1500.00', '900.00');

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    assertTotal(1500.00, $fila['programado']);
    assertTotal(900.00, $fila['cobrado']);
    assertTotal(600.00, $fila['pendiente']);
    assertTotal(60.0, $fila['porcentaje']);
});

it('sums several cuotas due today without duplicating amounts', function () {
    $credito = creditoActivoDeAsesor($this->asesor);
    cuotaHoyDe($credito, '1000.00', '1000.00', 1);
    cuotaHoyDe($credito, '500.00', '0.00', 2);

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    assertTotal(1500.00, $fila['programado']);
    assertTotal(1000.00, $fila['cobrado']);
    assertTotal(500.00, $fila['pendiente']);
});

it('reports 100% and zero pendiente when fully paid', function () {
    $credito = creditoActivoDeAsesor($this->asesor);
    cuotaHoyDe($credito, '2000.00', '2000.00');

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    assertTotal(100.0, $fila['porcentaje']);
    assertTotal(0.00, $fila['pendiente']);
});

it('reports 0% when nothing has been paid yet', function () {
    $credito = creditoActivoDeAsesor($this->asesor);
    cuotaHoyDe($credito, '1500.00', '0.00');

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    assertTotal(0.0, $fila['porcentaje']);
    assertTotal(1500.00, $fila['pendiente']);
});

it('reports a null porcentaje when there is nothing due today, avoiding division by zero', function () {
    // El asesor existe y es visible, pero sin cuota vencida hoy.
    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    expect($fila)->not->toBeNull();
    assertTotal(0.00, $fila['programado']);
    expect($fila['porcentaje'])->toBeNull();
});

it('excludes cuotas not yet due', function () {
    $credito = creditoActivoDeAsesor($this->asesor);
    cuotaHoyDe($credito, '1000.00', '0.00');

    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 2,
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        'monto_total' => '5000.00',
        'monto_abonado' => '0.00',
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    assertTotal(1000.00, $fila['programado']);
});

it('includes cuotas overdue from previous days, not just the ones due exactly today', function () {
    $credito = creditoActivoDeAsesor($this->asesor);
    cuotaHoyDe($credito, '1000.00', '0.00');

    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 2,
        'fecha_vencimiento' => now()->subDays(15)->toDateString(),
        'monto_total' => '293.00',
        'monto_abonado' => '0.00',
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    assertTotal(1293.00, $fila['programado']);
});

it('excludes cuotas already fully paid even if their vencimiento is on or before today', function () {
    $credito = creditoActivoDeAsesor($this->asesor);
    cuotaHoyDe($credito, '1000.00', '0.00');

    CuotaCredito::factory()->paraCredito($credito)->pagada()->create([
        'numero_cuota' => 2,
        'fecha_vencimiento' => now()->subDays(10)->toDateString(),
        'monto_total' => '800.00',
        'monto_abonado' => '800.00',
    ]);

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    assertTotal(1000.00, $fila['programado']);
});

it('excludes creditos that are liquidado or not yet activo', function () {
    $credito = creditoActivoDeAsesor($this->asesor, estado: 'liquidado');
    cuotaHoyDe($credito, '1000.00', '0.00');

    $pendiente = creditoActivoDeAsesor($this->asesor, estado: 'pendiente');
    cuotaHoyDe($pendiente, '2000.00', '0.00');

    Sanctum::actingAs($this->admin, ['*']);

    $fila = filaAsesorAvance(
        $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas'),
        $this->asesor->id,
    );

    assertTotal(0.00, $fila['programado']);
});

it('sorts ascending by porcentaje, with sin-programado last', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $sinProgramado = User::factory()->forAgencia($this->agencia)->create();
    $sinProgramado->assignRole('asesor');

    cuotaHoyDe(creditoActivoDeAsesor($this->asesor), '1000.00', '1000.00'); // 100%
    cuotaHoyDe(creditoActivoDeAsesor($otroAsesor), '1000.00', '0.00'); // 0%

    Sanctum::actingAs($this->admin, ['*']);

    $filas = $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas');
    $orden = array_column($filas, 'asesor_id');

    expect(array_search($otroAsesor->id, $orden))->toBeLessThan(array_search($this->asesor->id, $orden))
        ->and(array_search($this->asesor->id, $orden))->toBeLessThan(array_search($sinProgramado->id, $orden));
});

it('lets an asesor see their own row even though asesoresVisibles() would otherwise return empty for that role', function () {
    $credito = creditoActivoDeAsesor($this->asesor);
    cuotaHoyDe($credito, '1000.00', '400.00');

    Sanctum::actingAs($this->asesor, ['*']);

    $filas = $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas');

    expect($filas)->toHaveCount(1);
    assertTotal(1000.00, $filas[0]['programado']);
});

it('does not let an administrador_agencia see avance of another agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    $asesorOtraAgencia = User::factory()->forAgencia($this->otraAgencia)->create();
    $asesorOtraAgencia->assignRole('asesor');
    cuotaHoyDe(creditoActivoDeAsesor($asesorOtraAgencia), '1000.00', '0.00');

    Sanctum::actingAs($adminAgencia, ['*']);

    $filas = $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertSuccessful()->json('data.filas');

    expect(filaAsesorAvance($filas, $asesorOtraAgencia->id))->toBeNull();
});

it('filters by agencia_id and asesor_id', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    cuotaHoyDe(creditoActivoDeAsesor($this->asesor), '1000.00', '0.00');
    cuotaHoyDe(creditoActivoDeAsesor($otroAsesor), '2000.00', '0.00');

    Sanctum::actingAs($this->admin, ['*']);

    $porAsesor = $this->getJson("/api/dashboard/avance-cobranza-hoy?asesor_id={$this->asesor->id}")
        ->assertSuccessful()->json('data.filas');
    expect($porAsesor)->toHaveCount(1)
        ->and($porAsesor[0]['asesor_id'])->toBe($this->asesor->id);

    $porAgencia = $this->getJson("/api/dashboard/avance-cobranza-hoy?agencia_id={$this->agencia->id}")
        ->assertSuccessful()->json('data.filas');
    expect(array_column($porAgencia, 'asesor_id'))->toContain($this->asesor->id, $otroAsesor->id);
});

it('denies access without the dashboard.ver permission', function () {
    $sinRol = User::factory()->forEmpresa($this->empresa)->create();

    Sanctum::actingAs($sinRol, ['*']);

    $this->getJson('/api/dashboard/avance-cobranza-hoy')->assertForbidden();
});
