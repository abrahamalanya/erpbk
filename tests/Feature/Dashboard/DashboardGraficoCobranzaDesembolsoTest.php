<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

// cicloDe(), clienteEn(), créditoDe(), cobroHoy(), desembolsoHoy(), ventaDe()
// y pagoVentaHoy() viven en tests/Pest.php (compartidas con DashboardInicialTest).

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

/**
 * @return array<int, array<string, mixed>>
 */
function filasDe(TestResponse $response): array
{
    return $response->assertSuccessful()->json('data.filas');
}

function filaConClave(array $filas, string $clave, mixed $valor): ?array
{
    foreach ($filas as $fila) {
        if (($fila[$clave] ?? null) === $valor) {
            return $fila;
        }
    }

    return null;
}

it('defaults to agrupar_por=agencia', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/dashboard/grafico-cobranza-desembolso')->assertSuccessful()->json('data');

    expect($data['agrupado_por'])->toBe('agencia');
});

it('groups cobranza and desembolso by agencia', function () {
    $clienteA = clienteEn($this->agencia);
    $clienteB = clienteEn($this->otraAgencia);

    cobroHoy([], créditoDe($clienteA), $clienteA, $this->asesor, '100.00');
    desembolsoHoy(créditoDe($clienteA), $this->asesor, '40.00');

    $otroAsesor = User::factory()->forAgencia($this->otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    cobroHoy([], créditoDe($clienteB, registradoPor: $otroAsesor), $clienteB, $otroAsesor, '900.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=agencia'));

    expect($filas)->toHaveCount(2);

    $filaA = filaConClave($filas, 'agencia_id', $this->agencia->id);
    $filaB = filaConClave($filas, 'agencia_id', $this->otraAgencia->id);

    assertTotal(100.00, $filaA['cobranza']['total']);
    assertTotal(40.00, $filaA['desembolso']['total']);
    assertTotal(900.00, $filaB['cobranza']['total']);
    assertTotal(0.00, $filaB['desembolso']['total']);
});

it('does not list an agencia without any movement', function () {
    $cliente = clienteEn($this->agencia);
    cobroHoy([], créditoDe($cliente), $cliente, $this->asesor, '100.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=agencia'));

    expect($filas)->toHaveCount(1);
    expect(filaConClave($filas, 'agencia_id', $this->otraAgencia->id))->toBeNull();
});

it('groups by asesor, respecting the agencia filter', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');
    cobroHoy([], $credito, $cliente, $this->otroAsesor, '300.00');

    $clienteOtraAgencia = clienteEn($this->otraAgencia);
    $otroAsesorOtraAgencia = User::factory()->forAgencia($this->otraAgencia)->create();
    $otroAsesorOtraAgencia->assignRole('asesor');
    cobroHoy([], créditoDe($clienteOtraAgencia, registradoPor: $otroAsesorOtraAgencia), $clienteOtraAgencia, $otroAsesorOtraAgencia, '999.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = filasDe($this->getJson("/api/dashboard/grafico-cobranza-desembolso?agrupar_por=asesor&agencia_id={$this->agencia->id}"));

    expect($filas)->toHaveCount(2);
    assertTotal(100.00, filaConClave($filas, 'asesor_id', $this->asesor->id)['cobranza']['total']);
    assertTotal(300.00, filaConClave($filas, 'asesor_id', $this->otroAsesor->id)['cobranza']['total']);
    expect(filaConClave($filas, 'asesor_id', $otroAsesorOtraAgencia->id))->toBeNull();
});

it('groups by tipo_credito, filling every tipo including ventas with zero', function () {
    $cliente = clienteEn($this->agencia);

    cobroHoy([], créditoDe($cliente, 'prendario'), $cliente, $this->asesor, '100.00');
    desembolsoHoy(créditoDe($cliente, 'hipotecario'), $this->asesor, '250.00');

    $venta = ventaDe($cliente, $this->asesor, $this->agencia);
    pagoVentaHoy($venta, $this->asesor, '50.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=tipo_credito'));

    expect($filas)->toHaveCount(5)
        ->and(array_column($filas, 'tipo_credito'))->toEqual(['prendario', 'vehicular', 'hipotecario', 'diario', 'ventas']);

    assertTotal(100.00, filaConClave($filas, 'tipo_credito', 'prendario')['cobranza']['total']);
    assertTotal(0.00, filaConClave($filas, 'tipo_credito', 'prendario')['desembolso']['total']);

    assertTotal(0.00, filaConClave($filas, 'tipo_credito', 'hipotecario')['cobranza']['total']);
    assertTotal(250.00, filaConClave($filas, 'tipo_credito', 'hipotecario')['desembolso']['total']);

    assertTotal(0.00, filaConClave($filas, 'tipo_credito', 'vehicular')['cobranza']['total']);
    assertTotal(0.00, filaConClave($filas, 'tipo_credito', 'diario')['cobranza']['total']);

    assertTotal(50.00, filaConClave($filas, 'tipo_credito', 'ventas')['cobranza']['total']);
    assertTotal(0.00, filaConClave($filas, 'tipo_credito', 'ventas')['desembolso']['total']);
});

it('groups by agencia and tipo_credito, where each tipo total adds up to the agencia total', function () {
    $cliente = clienteEn($this->agencia);

    cobroHoy([], créditoDe($cliente, 'prendario'), $cliente, $this->asesor, '100.00');
    cobroHoy([], créditoDe($cliente, 'hipotecario'), $cliente, $this->asesor, '400.00');
    desembolsoHoy(créditoDe($cliente, 'prendario'), $this->asesor, '60.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=agencia_tipo_credito'));

    expect($filas)->toHaveCount(1);
    $fila = $filas[0];

    expect($fila['agencia_id'])->toBe($this->agencia->id)
        ->and($fila['tipos'])->toHaveCount(5);

    assertTotal(500.00, $fila['cobranza']['total']);
    assertTotal(60.00, $fila['desembolso']['total']);

    $sumaTiposCobranza = array_sum(array_column(array_column($fila['tipos'], 'cobranza'), 'total'));
    $sumaTiposDesembolso = array_sum(array_column(array_column($fila['tipos'], 'desembolso'), 'total'));

    assertTotal($fila['cobranza']['total'], $sumaTiposCobranza);
    assertTotal($fila['desembolso']['total'], $sumaTiposDesembolso);

    assertTotal(100.00, filaConClave($fila['tipos'], 'tipo_credito', 'prendario')['cobranza']['total']);
    assertTotal(60.00, filaConClave($fila['tipos'], 'tipo_credito', 'prendario')['desembolso']['total']);
    assertTotal(400.00, filaConClave($fila['tipos'], 'tipo_credito', 'hipotecario')['cobranza']['total']);
});

it('keeps the tipo_credito filter narrowing the agencia breakdown', function () {
    $cliente = clienteEn($this->agencia);

    cobroHoy([], créditoDe($cliente, 'prendario'), $cliente, $this->asesor, '100.00');
    cobroHoy([], créditoDe($cliente, 'hipotecario'), $cliente, $this->asesor, '400.00');

    Sanctum::actingAs($this->admin, ['*']);

    $filas = filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=agencia&tipo_credito=hipotecario'));

    assertTotal(400.00, filaConClave($filas, 'agencia_id', $this->agencia->id)['cobranza']['total']);
});

it('does not let an asesor see another agencia in the agencia breakdown', function () {
    $clientePropio = clienteEn($this->agencia);
    $clienteAjeno = Cliente::factory()->forAgencia($this->otraAgencia)->create([
        'empresa_id' => $this->empresa->id,
    ]);

    $otroAsesor = User::factory()->forAgencia($this->otraAgencia)->create();
    $otroAsesor->assignRole('asesor');

    cobroHoy([], créditoDe($clientePropio, registradoPor: $this->asesor), $clientePropio, $this->asesor, '100.00');
    cobroHoy([], créditoDe($clienteAjeno, registradoPor: $otroAsesor), $clienteAjeno, $otroAsesor, '900.00');

    Sanctum::actingAs($this->asesor, ['*']);

    $filas = filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=agencia'));

    expect($filas)->toHaveCount(1);
    assertTotal(100.00, $filas[0]['cobranza']['total']);
});

it('denies access without the dashboard.ver permission', function () {
    $sinRol = User::factory()->forEmpresa($this->empresa)->create();

    Sanctum::actingAs($sinRol, ['*']);

    $this->getJson('/api/dashboard/grafico-cobranza-desembolso')->assertForbidden();
});

it('rejects an invalid agrupar_por', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=inventado')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('agrupar_por');
});

it('sums cobranza and desembolso over the whole desde/hasta range, not just today', function () {
    $cliente = clienteEn($this->agencia);
    $credito = créditoDe($cliente);

    $cicloAyer = cicloEnFecha($this->ciclo, now()->subDay()->toDateString());
    cobroHoy(['caja_ciclo_id' => $cicloAyer->id, 'created_at' => now()->subDay()], $credito, $cliente, $this->asesor, '200.00');
    cobroHoy([], $credito, $cliente, $this->asesor, '100.00');

    Sanctum::actingAs($this->admin, ['*']);

    $desde = now()->subDay()->toDateString();
    $hasta = now()->toDateString();

    $filas = filasDe($this->getJson("/api/dashboard/grafico-cobranza-desembolso?agrupar_por=agencia&desde={$desde}&hasta={$hasta}"));

    assertTotal(300.00, filaConClave($filas, 'agencia_id', $this->agencia->id)['cobranza']['total']);
});

it('rejects an invalid desde/hasta range', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $desde = now()->toDateString();
    $hasta = now()->subDay()->toDateString();

    $this->getJson("/api/dashboard/grafico-cobranza-desembolso?desde={$desde}&hasta={$hasta}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hasta');
});

it('returns an empty filas list when there is no movement at all', function () {
    Sanctum::actingAs($this->admin, ['*']);

    expect(filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=agencia')))->toBe([]);
    expect(filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=asesor')))->toBe([]);

    $filasTipo = filasDe($this->getJson('/api/dashboard/grafico-cobranza-desembolso?agrupar_por=tipo_credito'));
    expect($filasTipo)->toHaveCount(5);

    foreach ($filasTipo as $fila) {
        assertTotal(0.00, $fila['cobranza']['total']);
        assertTotal(0.00, $fila['desembolso']['total']);
    }
});
