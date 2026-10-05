<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Reportes\Tipos\AtrasoVencimiento;
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

    $this->otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $this->otroAsesor->assignRole('asesor');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create(['asesor_id' => $this->asesor->id]);

    $this->clienteOtro = Cliente::factory()->forAgencia($this->agencia)->create(['asesor_id' => $this->otroAsesor->id]);
});

/**
 * Crédito con $cuotas cuotas de $montoCuota y con el crédito completo vencido
 * hace $diasVencimiento días: eso es lo único que decide la franja.
 */
function creditoEnFranja(
    Cliente $cliente,
    User $asesor,
    int $diasVencimiento,
    int $cuotas = 3,
    float $montoCuota = 110.0,
    ?Agencia $agencia = null,
): Credito {
    $agencia ??= $cliente->agencia;

    $credito = Credito::factory()->diario()->create([
        'registrado_por' => $asesor->id,
        'empresa_id' => $agencia->empresa_id,
        'agencia_id' => $agencia->id,
        'cliente_id' => $cliente->id,
        'estado' => 'activo',
        'fecha_desembolso' => now()->subDays(60)->toDateString(),
        'fecha_vencimiento' => now()->subDays($diasVencimiento)->toDateString(),
    ]);

    for ($n = 1; $n <= $cuotas; $n++) {
        CuotaCredito::factory()->paraCredito($credito)->create([
            'numero_cuota' => $n,
            'fecha_vencimiento' => now()->subDays(5)->addDays($n)->toDateString(),
            'monto_capital' => $montoCuota - 10,
            'monto_interes' => 10,
            'monto_total' => $montoCuota,
        ]);
    }

    return $credito;
}

/** Cobro registrado HOY por el monto indicado. */
function cobroDeHoy(Credito $credito, float $monto = 100.0, string $estado = 'registrado'): Cobro
{
    return Cobro::factory()->create([
        'empresa_id' => $credito->empresa_id,
        'cliente_id' => $credito->cliente_id,
        'credito_id' => $credito->id,
        'estado' => $estado,
        'monto_pagado' => $monto,
        'medio' => 'yape',
        'created_at' => now(),
    ]);
}

/** El tramo de una línea de tiempo, por su key. */
function tramo(array $payload, string $key): array
{
    return collect($payload['tramos'])->firstWhere('key', $key);
}

/**
 * PHP's json_encode() escribe un float sin parte decimal como entero (400.0 ->
 * 400), así que el frontend recibe 400 y no 400.0. El número es el mismo; por
 * eso los montos y porcentajes se castean antes de comparar en vez de relajar
 * la aserción a toEqual(), que también aceptaría un string.
 */
function numero(mixed $valor): float
{
    return (float) $valor;
}

it('devuelve siempre los cuatro tramos, en orden de menor a mayor riesgo', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect(collect($data['tramos'])->pluck('key')->all())
        ->toBe(['verde', 'amarillo', 'rojo', 'sin_clasificar']);

    // Aunque la cartera esté vacía, la barra tiene que poder pintarse entera.
    expect($data['total_creditos'])->toBe(0)
        ->and($data['tramos'])->toHaveCount(4)
        ->and(tramo($data, 'verde')['color'])->toBe('#22c55e')
        ->and(tramo($data, 'amarillo')['color'])->toBe('#f5c518')
        ->and(tramo($data, 'rojo')['color'])->toBe('#ef4444')
        ->and(tramo($data, 'sin_clasificar')['color'])->toBeNull();
});

it('reutiliza los mismos colores del reporte de atrasos', function () {
    // Si el panel inventa su propia paleta, el mismo crédito se ve de un color
    // en el PDF de atrasos y de otro en el inicio.
    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect(tramo($data, 'verde')['color'])->toBe(AtrasoVencimiento::Normal->color())
        ->and(tramo($data, 'amarillo')['color'])->toBe(AtrasoVencimiento::Deficiente->color())
        ->and(tramo($data, 'rojo')['color'])->toBe(AtrasoVencimiento::Perdida->color());
});

it('coloca cada crédito en la franja que le corresponde', function (int $dias, string $franja) {
    creditoEnFranja($this->cliente, $this->asesor, $dias);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect(tramo($data, $franja)['creditos'])->toBe(1)
        ->and($data['total_creditos'])->toBe(1);
})->with([
    'Normal (5 días) -> verde' => [5, 'verde'],
    'Problema potencial (10 días) -> verde' => [10, 'verde'],
    'Deficiente (11 días) -> amarillo' => [11, 'amarillo'],
    'Dudoso (30 días) -> amarillo' => [30, 'amarillo'],
    'Pérdida (31 días) -> rojo' => [31, 'rojo'],
    'Castigado (+60 días) -> rojo' => [61, 'rojo'],
]);

it('deja sin color el crédito que aun no vence', function () {
    // Un crédito que vence hoy todavía no está atrasado: diffInDays() es
    // absoluto y sin este cuidado saldría con días positivos falsos.
    creditoEnFranja($this->cliente, $this->asesor, 0);
    $futuro = Credito::factory()->diario()->create([
        'registrado_por' => $this->asesor->id,
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'estado' => 'activo',
        'fecha_desembolso' => now()->toDateString(),
        'fecha_vencimiento' => now()->addDays(30)->toDateString(),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect(tramo($data, 'sin_clasificar')['creditos'])->toBe(2)
        ->and(tramo($data, 'sin_clasificar')['rango'])->toBe('Sin vencer')
        ->and(numero(tramo($data, 'sin_clasificar')['monto_total']))->toBe(330.0);
});

it('cuenta las seis categorias del reporte en las tres franjas', function () {
    // Un crédito por categoría del semáforo, para que ninguna se pierda entre
    // la clasificación del reporte y el agrupado del panel.
    foreach ([5, 10, 15, 25, 45, 61] as $dias) {
        creditoEnFranja($this->cliente, $this->asesor, $dias);
    }

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect(tramo($data, 'verde')['creditos'])->toBe(2)
        ->and(tramo($data, 'amarillo')['creditos'])->toBe(2)
        ->and(tramo($data, 'rojo')['creditos'])->toBe(2);

    // Y la leyenda dice qué entra en cada una.
    expect(tramo($data, 'verde')['categorias'])->toBe(['Normal', 'Problema potencial'])
        ->and(tramo($data, 'amarillo')['categorias'])->toBe(['Deficiente', 'Dudoso'])
        ->and(tramo($data, 'rojo')['categorias'])->toBe(['Pérdida', 'Castigado']);
});

it('replica los numeros del ejemplo: 60 creditos, 33 cobrados y el dinero por franja', function () {
    // El ejemplo que dio el usuario: 10 rojos (2000) / 40 verdes (3000) /
    // 10 sin color (1000) = 60 créditos por 6000, con 3 / 30 / 0 cobrados.
    $rojos = [];
    $verdes = [];
    $sinColor = [];

    foreach (range(1, 10) as $i) {
        // 2000 entre 10 créditos = 200 por crédito.
        $rojos[] = creditoEnFranja($this->cliente, $this->asesor, 45, cuotas: 2, montoCuota: 100.0);
    }
    foreach (range(1, 40) as $i) {
        // 3000 entre 40 créditos = 75 por crédito.
        $verdes[] = creditoEnFranja($this->cliente, $this->asesor, 8, cuotas: 1, montoCuota: 75.0);
    }
    foreach (range(1, 10) as $i) {
        $sinColor[] = creditoEnFranja($this->cliente, $this->asesor, -10, cuotas: 1, montoCuota: 100.0);
    }

    // 3 rojos cobrados por 300 (100 c/u) y 30 verdes por 700 exacto: 20 de
    // ellos a 25 y 10 a 20, para que la suma no dependa de repetir un
    // decimal y "700/30 × 30" dé 699.9999999999999.
    foreach (array_slice($rojos, 0, 3) as $c) {
        cobroDeHoy($c, 100.0);
    }
    foreach (array_slice($verdes, 0, 20) as $c) {
        cobroDeHoy($c, 25.0);
    }
    foreach (array_slice($verdes, 20, 10) as $c) {
        cobroDeHoy($c, 20.0);
    }

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    // Línea de tiempo 1: cantidad.
    expect($data['total_creditos'])->toBe(60)
        ->and($data['cobrados'])->toBe(33)
        ->and(numero($data['porcentaje_cobrado']))->toBe(55.0)
        ->and(tramo($data, 'rojo')['creditos'])->toBe(10)
        ->and(tramo($data, 'rojo')['cobrados'])->toBe(3)
        ->and(numero(tramo($data, 'rojo')['porcentaje_cobrado']))->toBe(30.0)
        ->and(tramo($data, 'verde')['creditos'])->toBe(40)
        ->and(tramo($data, 'verde')['cobrados'])->toBe(30)
        ->and(numero(tramo($data, 'verde')['porcentaje_cobrado']))->toBe(75.0)
        ->and(tramo($data, 'sin_clasificar')['cobrados'])->toBe(0)
        ->and(numero(tramo($data, 'sin_clasificar')['porcentaje_cobrado']))->toBe(0.0);

    // Línea de tiempo 2: dinero (capital + interés).
    expect(numero($data['total_monto']))->toBe(6000.0)
        ->and(numero($data['monto_cobrado']))->toBe(1000.0)
        ->and(numero($data['porcentaje_cobrado_monto']))->toBe(16.67)
        ->and(numero(tramo($data, 'rojo')['monto_total']))->toBe(2000.0)
        ->and(numero(tramo($data, 'rojo')['monto_cobrado']))->toBe(300.0)
        ->and(numero(tramo($data, 'rojo')['porcentaje_cobrado_monto']))->toBe(15.0)
        ->and(numero(tramo($data, 'verde')['monto_total']))->toBe(3000.0)
        ->and(numero(tramo($data, 'verde')['monto_cobrado']))->toBe(700.0)
        ->and(numero(tramo($data, 'verde')['porcentaje_cobrado_monto']))->toBe(23.33)
        ->and(numero(tramo($data, 'sin_clasificar')['monto_total']))->toBe(1000.0)
        ->and(numero(tramo($data, 'sin_clasificar')['monto_cobrado']))->toBe(0.0);

    // Participación de cada franja sobre el total de la cartera.
    expect(numero(tramo($data, 'rojo')['participacion']))->toBe(16.67)
        ->and(numero(tramo($data, 'verde')['participacion']))->toBe(66.67)
        ->and(numero(tramo($data, 'sin_clasificar')['participacion']))->toBe(16.67)
        ->and(numero(tramo($data, 'verde')['participacion_monto']))->toBe(50.0);
});

it('cuenta como cobrado solo lo que se cobro HOY', function () {
    $hoy = creditoEnFranja($this->cliente, $this->asesor, 45);
    $ayer = creditoEnFranja($this->cliente, $this->asesor, 45);
    $nunca = creditoEnFranja($this->cliente, $this->asesor, 45);

    cobroDeHoy($hoy, 100);
    Cobro::factory()->create([
        'empresa_id' => $this->empresa->id,
        'cliente_id' => $this->cliente->id,
        'credito_id' => $ayer->id,
        'estado' => 'registrado',
        'monto_pagado' => 100,
        'medio' => 'yape',
        'created_at' => now()->subDay(),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    // El cobro de ayer no cuenta como avance de HOY, aunque el crédito sí haya
    // pagado algo en su historia.
    expect($data['cobrados'])->toBe(1)
        ->and(numero($data['monto_cobrado']))->toBe(100.0)
        ->and(tramo($data, 'rojo')['creditos'])->toBe(3);
});

it('ignora los cobros anulados', function () {
    $credito = creditoEnFranja($this->cliente, $this->asesor, 45);
    cobroDeHoy($credito, 500, 'anulado');

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect($data['cobrados'])->toBe(0)
        ->and(numero($data['monto_cobrado']))->toBe(0.0);
});

it('no cuenta un cobro de monto cero como avance de cobranza', function () {
    $credito = creditoEnFranja($this->cliente, $this->asesor, 45);
    cobroDeHoy($credito, 0);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    // Marca la línea de tiempo con un 100% sin que haya entrado un sol.
    expect($data['cobrados'])->toBe(0)
        ->and(numero($data['porcentaje_cobrado']))->toBe(0.0);
});

it('devuelve porcentaje null en vez de cero cuando no hay cartera', function () {
    // Un 0% con cartera vacía se lee como "no cobramos nada", que es un dato
    // falso: no es que la cobranza falló, es que no hay nada que cobrar.
    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect($data['porcentaje_cobrado'])->toBeNull()
        ->and($data['porcentaje_cobrado_monto'])->toBeNull()
        ->and(tramo($data, 'rojo')['participacion'])->toBeNull()
        ->and(tramo($data, 'rojo')['porcentaje_cobrado'])->toBeNull()
        ->and(tramo($data, 'rojo')['porcentaje_cobrado_monto'])->toBeNull();
});

it('suma el capital mas el interes de todas las cuotas como monto total', function () {
    creditoEnFranja($this->cliente, $this->asesor, 45, cuotas: 4, montoCuota: 100.0);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    // 4 cuotas de 100 = 400, que es la columna Total del reporte (no el saldo).
    expect(numero($data['total_monto']))->toBe(400.0)
        ->and(numero(tramo($data, 'rojo')['monto_total']))->toBe(400.0);
});

it('filtra por agencia', function () {
    creditoEnFranja($this->cliente, $this->asesor, 45);
    $deOtra = Cliente::factory()->forAgencia($this->otraAgencia)->create(['asesor_id' => $this->asesor->id]);
    creditoEnFranja($deOtra, $this->asesor, 45);

    Sanctum::actingAs($this->asesor, ['*']);

    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');
    expect($data['total_creditos'])->toBe(2);

    $filtrado = $this->getJson("/api/dashboard/linea-de-tiempo-atrasos?agencia_id={$this->agencia->id}")
        ->assertSuccessful()->json('data');
    expect($filtrado['total_creditos'])->toBe(1);
});

it('filtra por asesor tomando el asesor del cliente', function () {
    creditoEnFranja($this->cliente, $this->asesor, 45);
    creditoEnFranja($this->clienteOtro, $this->otroAsesor, 45);

    Sanctum::actingAs($this->asesor, ['*']);

    // El Asesor es la columna del reporte, que sale de cliente.asesor.
    $filtrado = $this->getJson("/api/dashboard/linea-de-tiempo-atrasos?asesor_id={$this->asesor->id}")
        ->assertSuccessful()->json('data');

    expect($filtrado['total_creditos'])->toBe(1)
        ->and(tramo($filtrado, 'rojo')['creditos'])->toBe(1);
});

it('filtra por tipo de credito', function () {
    creditoEnFranja($this->cliente, $this->asesor, 45);
    Credito::factory()->create([
        'registrado_por' => $this->asesor->id,
        'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id,
        'tipo_credito' => 'prendario',
        'estado' => 'activo',
        'fecha_vencimiento' => now()->subDays(45)->toDateString(),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos?tipo_credito=diario')
        ->assertSuccessful()->json('data');

    expect($data['total_creditos'])->toBe(1);
});

it('rechaza un filtro invalido', function (string $query, string $campo) {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson("/api/dashboard/linea-de-tiempo-atrasos?{$query}")->assertUnprocessable()
        ->assertJsonValidationErrors($campo);
})->with([
    'agencia inexistente' => ['agencia_id=999999', 'agencia_id'],
    'asesor inexistente' => ['asesor_id=999999', 'asesor_id'],
    'tipo de credito invalido' => ['tipo_credito=inventado', 'tipo_credito'],
]);

it('acota al asesor a su propia cartera', function () {
    creditoEnFranja($this->cliente, $this->asesor, 45);
    creditoEnFranja($this->clienteOtro, $this->otroAsesor, 45);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect($data['total_creditos'])->toBe(1);
});

it('acota al administrador de agencia a su agencia', function () {
    creditoEnFranja($this->cliente, $this->asesor, 45);
    $deOtra = Cliente::factory()->forAgencia($this->otraAgencia)->create();
    creditoEnFranja($deOtra, $this->asesor, 45);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');

    Sanctum::actingAs($admin, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    expect($data['total_creditos'])->toBe(1);
});

it('deja fuera los creditos ya cancelados o liquidados', function () {
    creditoEnFranja($this->cliente, $this->asesor, 45);

    $cancelado = creditoEnFranja($this->cliente, $this->asesor, 200);
    $cancelado->update(['estado' => 'cancelado']);

    $liquidado = creditoEnFranja($this->cliente, $this->asesor, 200);
    $liquidado->update(['estado' => 'liquidado']);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');

    // Solo el activo entra; cancelado/liquidado ya no arrastran mora.
    expect($data['total_creditos'])->toBe(1);
});

it('rechaza a un usuario sin dashboard.ver', function () {
    creditoEnFranja($this->cliente, $this->asesor, 45);

    $secretaria = User::factory()->forEmpresa($this->empresa)->create();
    $secretaria->assignRole('secretaria');

    Sanctum::actingAs($secretaria, ['*']);

    $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertForbidden();
});

it('reconcilia con el reporte de atrasos sin diferencia de unidad', function () {
    foreach ([5, 15, 45, 61] as $dias) {
        creditoEnFranja($this->cliente, $this->asesor, $dias);
    }

    Sanctum::actingAs($this->asesor, ['*']);

    $linea = $this->getJson('/api/dashboard/linea-de-tiempo-atrasos')->assertSuccessful()->json('data');
    $reporte = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data');

    // Mismo universo de filas: el panel agrupa, no recortó nada.
    expect($linea['total_creditos'])->toBe(count($reporte))
        ->and($linea['total_creditos'])->toBe(4);

    // Y el dinero también cuadra con la columna Total del reporte.
    expect(numero($linea['total_monto']))->toBe(round((float) array_sum(array_column($reporte, 'total')), 2));
});
