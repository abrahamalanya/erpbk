<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Planilla\Models\Planilla;
use App\Modules\Planilla\Models\PlanillaDescuento;
use App\Modules\Planilla\Models\PlanillaDescuentoAplicacion;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->otraAgencia = Agencia::factory()->for($this->empresa)->create();

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->trabajador = User::factory()->forAgencia($this->agencia)->create([
        'nombre' => 'Norma',
        'apellido' => 'Quispe',
        'dni' => '74901226',
    ]);
    $this->trabajador->assignRole('asesor');
});

/** Crea la cabecera de la planilla por API y devuelve su id. */
function crearPlanilla(User $actor, string $periodo = '2026-09', ?int $agenciaId = null): int
{
    return test()->postJson('/api/planillas', array_filter([
        'periodo' => $periodo,
        'fecha_pago' => '2026-09-30',
        'agencia_id' => $agenciaId,
    ]))->assertCreated()->json('data.id');
}

/** Registra un descuento (adelanto/préstamo/falta) por API. */
function crearDescuento(User $actor, User $trabajador, float $monto, ?float $cuota = null, string $tipo = 'adelanto'): int
{
    return test()->postJson('/api/planilla-descuentos', array_filter([
        'user_id' => $trabajador->id,
        'tipo' => $tipo,
        'concepto' => 'Adelanto de sueldo',
        'monto' => $monto,
        'cuota' => $cuota,
        'fecha_registro' => '2026-09-01',
    ]))->assertCreated()->json('data.id');
}

it('creates the planilla cabecera for a periodo', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $response = $this->postJson('/api/planillas', [
        'periodo' => '2026-09',
        'fecha_pago' => '2026-09-30',
    ])->assertCreated();

    expect($response->json('data.periodo'))->toBe('2026-09')
        ->and($response->json('data.estado'))->toBe('borrador');
});

it('is idempotent: creating the same periodo twice returns the existing planilla', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $id = crearPlanilla($this->administradorGeneral);
    $otra = crearPlanilla($this->administradorGeneral);

    expect($otra)->toBe($id)
        ->and(Planilla::query()->count())->toBe(1);
});

it('rejects an invalid periodo and fecha_pago', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson('/api/planillas', ['periodo' => 'septiembre', 'fecha_pago' => '2026-09-30'])
        ->assertUnprocessable()->assertJsonValidationErrors('periodo');

    $this->postJson('/api/planillas', ['periodo' => '2026-09'])->assertUnprocessable()
        ->assertJsonValidationErrors('fecha_pago');
});

it('registers lineas, calculates aportes and neto, and freezes the totales', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [
            ['user_id' => $this->trabajador->id, 'ingresos' => 1130.00, 'es_afp' => false],
        ],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    // Config por defecto: ONP 3% + Essalud 4% trabajador; Essalud 5% + CTS 8.33% empleador.
    // 1130 * 3% = 33.90 (ONP) + 1130 * 4% = 45.20 (Essalud) = 79.10
    expect($linea['onp'])->toBe('33.90')
        ->and($linea['afp'])->toBe('0.00')
        ->and($linea['essalud_trabajador'])->toBe('45.20')
        ->and($linea['tributos_trabajador'])->toBe('79.10')
        // neto = ingresos - descuentos(0) - tributos(79.10)
        ->and($linea['neto'])->toBe('1050.90')
        // empleador: 1130 * 5% = 56.50 (Essalud) + 1130 * 8.33% = 94.13 (CTS) = 150.63
        ->and($linea['aportes_empleador'])->toBe('150.63');

    // Los totales de la cabecera reflejan la línea.
    expect($response->json('data.total_ingresos'))->toBe('1130.00')
        ->and($response->json('data.total_neto'))->toBe('1050.90');
});

it('calculates aportes on the sueldo without bonificaciones', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [
            ['user_id' => $this->trabajador->id, 'ingresos' => 1000.00, 'bonificaciones' => 200.00, 'es_afp' => false],
        ],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    // El sueldo declarado (1000) es la base de los aportes del trabajador:
    // 1000 * 3% = 30 (ONP) + 1000 * 4% = 40 (Essalud) = 70
    expect($linea['ingresos'])->toBe('1200.00')
        ->and($linea['tributos_trabajador'])->toBe('70.00')
        // El empleador sí calcula sobre el sueldo completo: 1200 * 5% = 60 + 1200 * 8.33% = 99.96 = 159.96
        ->and($linea['aportes_empleador'])->toBe('159.96')
        ->and($linea['neto'])->toBe('1130.00');
});

it('takes the AFP monto as declared, not from a global rate', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [
            ['user_id' => $this->trabajador->id, 'ingresos' => 1000.00, 'es_afp' => true, 'afp' => 87.35],
        ],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    // El AFP es el declarado (87.35, no el 10% de 1000 que daría 100), y la
    // Essalud sigue automática: 1000 * 4% = 40.
    expect($linea['afp'])->toBe('87.35')
        ->and($linea['onp'])->toBe('0.00')
        ->and($linea['essalud_trabajador'])->toBe('40.00')
        ->and($linea['tributos_trabajador'])->toBe('127.35')
        ->and($linea['neto'])->toBe('872.65');
});

it('ignores the AFP monto when the trabajador is not in AFP', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [
            // es_afp false: es ONP, y el monto de AFP que venga se descarta.
            ['user_id' => $this->trabajador->id, 'ingresos' => 1000.00, 'es_afp' => false, 'afp' => 87.35],
        ],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    expect($linea['afp'])->toBe('0.00')
        ->and($linea['onp'])->toBe('30.00')
        ->and($linea['tributos_trabajador'])->toBe('70.00');
});

it('defaults the AFP to zero when not informed', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00, 'es_afp' => true]],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    expect($linea['afp'])->toBe('0.00')
        ->and($linea['tributos_trabajador'])->toBe('40.00');
});

it('rejects a negative AFP', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00, 'es_afp' => true, 'afp' => -50]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lineas.0.afp');
});

it('aplica el descuento pendiente del mes en la linea', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    // Adelanto de 100 sin cuota: se descuenta entero este mes.
    crearDescuento($this->administradorGeneral, $this->trabajador, 100.00);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00, 'es_afp' => false]],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    // 1000 - 100 (adelanto) - 70 (tributos) = 830
    expect($linea['descuentos_pagado'])->toBe('100.00')
        ->and($linea['neto'])->toBe('830.00');
});

it('descuentos pagados no se consumen hasta cerrar la planilla', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $descuentoId = crearDescuento($this->administradorGeneral, $this->trabajador, 100.00);

    // Armar el borrador calcula el descuento pero NO toca el saldo.
    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful();

    expect(PlanillaDescuento::findOrFail($descuentoId)->saldo)->toBe('100.00');

    // Cerrar sí lo descuenta.
    $this->postJson("/api/planillas/{$planillaId}/cerrar")->assertSuccessful();

    $descuento = PlanillaDescuento::findOrFail($descuentoId);
    expect($descuento->pagado)->toBe('100.00')
        ->and($descuento->saldo)->toBe('0.00')
        ->and($descuento->estado)->toBe('saldado');
});

it('un descuento en cuotas solo descuenta su cuota por mes', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    // Préstamo de 300 en cuotas de 100.
    crearDescuento($this->administradorGeneral, $this->trabajador, 300.00, 100.00, 'prestamo');

    // Mes 1
    $mes1 = crearPlanilla($this->administradorGeneral, '2026-09');
    $linea1 = collect($this->postJson("/api/planillas/{$mes1}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful()->json('data.trabajadores'))->first();
    expect($linea1['descuentos_pagado'])->toBe('100.00');
    $this->postJson("/api/planillas/{$mes1}/cerrar")->assertSuccessful();

    // Mes 2: la siguiente cuota.
    $mes2 = crearPlanilla($this->administradorGeneral, '2026-10');
    $linea2 = collect($this->postJson("/api/planillas/{$mes2}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful()->json('data.trabajadores'))->first();
    expect($linea2['descuentos_pagado'])->toBe('100.00');

    // Mes 3: la última cuota, y ya no queda saldo.
    $this->postJson("/api/planillas/{$mes2}/cerrar")->assertSuccessful();
    $mes3 = crearPlanilla($this->administradorGeneral, '2026-11');
    $linea3 = collect($this->postJson("/api/planillas/{$mes3}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful()->json('data.trabajadores'))->first();
    expect($linea3['descuentos_pagado'])->toBe('100.00');
    $this->postJson("/api/planillas/{$mes3}/cerrar")->assertSuccessful();

    // Mes 4: ya no hay nada que descontar.
    $mes4 = crearPlanilla($this->administradorGeneral, '2026-12');
    $linea4 = collect($this->postJson("/api/planillas/{$mes4}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful()->json('data.trabajadores'))->first();
    expect($linea4['descuentos_pagado'])->toBe('0.00');
});

it('una planilla cerrada no admite mas cambios', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);
    $this->postJson("/api/planillas/{$planillaId}/cerrar")->assertSuccessful();

    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertUnprocessable();

    $this->postJson("/api/planillas/{$planillaId}/cerrar")->assertUnprocessable();
});

it('reabre a planilla cerrada para editarla y volver a cerrarla', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful();
    $this->postJson("/api/planillas/{$planillaId}/cerrar")->assertSuccessful();

    $reabierta = $this->postJson("/api/planillas/{$planillaId}/reabrir")->assertSuccessful();
    expect($reabierta->json('data.estado'))->toBe('borrador')
        ->and($reabierta->json('data.cerrada_at'))->toBeNull();

    // Ahora sí admite cambios.
    $editada = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1500.00]],
    ])->assertSuccessful();
    expect(collect($editada->json('data.trabajadores'))->first()['ingresos'])->toBe('1500.00');

    $this->postJson("/api/planillas/{$planillaId}/cerrar")->assertSuccessful();
    expect(Planilla::findOrFail($planillaId)->estado)->toBe('confirmada');
});

it('devuelve los descuentos al saldo al reabrir, sin descontarlos dos veces al volver a cerrar', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    // Adelanto de 100 para el mes 1.
    $descuentoId = crearDescuento($this->administradorGeneral, $this->trabajador, 100.00);

    $mes1 = crearPlanilla($this->administradorGeneral, '2026-09');
    $this->postJson("/api/planillas/{$mes1}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful();
    $this->postJson("/api/planillas/{$mes1}/cerrar")->assertSuccessful();

    expect(PlanillaDescuento::findOrFail($descuentoId)->saldo)->toBe('0.00');

    // Reabrir devuelve el adelanto a su saldo.
    $this->postJson("/api/planillas/{$mes1}/reabrir")->assertSuccessful();
    expect(PlanillaDescuento::findOrFail($descuentoId)->saldo)->toBe('100.00')
        ->and(PlanillaDescuento::findOrFail($descuentoId)->pagado)->toBe('0.00')
        ->and(PlanillaDescuento::findOrFail($descuentoId)->estado)->toBe('pendiente');

    // Volver a cerrar lo descuenta UNA vez, no dos.
    $this->postJson("/api/planillas/{$mes1}/cerrar")->assertSuccessful();
    $descuento = PlanillaDescuento::findOrFail($descuentoId);
    expect($descuento->pagado)->toBe('100.00')
        ->and($descuento->saldo)->toBe('0.00');
});

it('reabrir una planilla ya en borrador falla', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $this->postJson("/api/planillas/{$planillaId}/reabrir")->assertUnprocessable();
});

it('devuelve los descuentos correctos al reabrir cuando el trabajador tiene varios', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    // Un adelanto entero (40) y un préstamo en cuotas (60, a 30/mes).
    $adelanto = crearDescuento($this->administradorGeneral, $this->trabajador, 40.00);
    $prestamo = crearDescuento($this->administradorGeneral, $this->trabajador, 60.00, 30.00, 'prestamo');

    $mes1 = crearPlanilla($this->administradorGeneral, '2026-09');
    $this->postJson("/api/planillas/{$mes1}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful();
    $this->postJson("/api/planillas/{$mes1}/cerrar")->assertSuccessful();

    // El adelanto se va entero (40); el préstamo solo su cuota (30).
    expect(PlanillaDescuento::findOrFail($adelanto)->saldo)->toBe('0.00')
        ->and(PlanillaDescuento::findOrFail($adelanto)->estado)->toBe('saldado')
        ->and(PlanillaDescuento::findOrFail($prestamo)->saldo)->toBe('30.00');

    // Al reabrir, cada uno recupera exactamente lo que se le descontó.
    $this->postJson("/api/planillas/{$mes1}/reabrir")->assertSuccessful();
    expect(PlanillaDescuento::findOrFail($adelanto)->saldo)->toBe('40.00')
        ->and(PlanillaDescuento::findOrFail($adelanto)->estado)->toBe('pendiente')
        ->and(PlanillaDescuento::findOrFail($prestamo)->saldo)->toBe('60.00')
        ->and(PlanillaDescuento::findOrFail($prestamo)->pagado)->toBe('0.00');

    // Y al volver a cerrar, se descuenta una vez el adelanto entero y la cuota
    // del préstamo — sin duplicar.
    $this->postJson("/api/planillas/{$mes1}/cerrar")->assertSuccessful();
    expect(PlanillaDescuento::findOrFail($adelanto)->saldo)->toBe('0.00')
        ->and(PlanillaDescuento::findOrFail($prestamo)->saldo)->toBe('30.00');

    // Y el rastro de aplicaciones se re-creó para el segundo cierre (auditable).
    expect(PlanillaDescuentoAplicacion::query()->count())->toBe(2);
});

it('rejects a repeated trabajador in the same planilla and a negative ingreso', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [
            ['user_id' => $this->trabajador->id, 'ingresos' => 1000.00],
            ['user_id' => $this->trabajador->id, 'ingresos' => 900.00],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('lineas.0.user_id');

    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => -100]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lineas.0.ingresos');
});

it('scopes visibility: administrador_agencia sees only their agencia, general sees all', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $propia = crearPlanilla($this->administradorGeneral, '2026-09', $this->agencia->id);
    $otra = crearPlanilla($this->administradorGeneral, '2026-09', $this->otraAgencia->id);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $todos = collect($this->getJson('/api/planillas')->assertSuccessful()->json('data'));
    expect($todos)->toHaveCount(2);

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $suyas = collect($this->getJson('/api/planillas')->assertSuccessful()->json('data'));
    expect($suyas)->toHaveCount(1)
        ->and($suyas->first()['id'])->toBe($propia);

    // Y no puede ver la de otra agencia.
    $this->getJson("/api/planillas/{$otra}")->assertForbidden();
    $this->getJson("/api/planillas/{$propia}")->assertSuccessful();
});

it('denies the report to a role without the planillas permissions', function () {
    Sanctum::actingAs($this->trabajador, ['*']);

    $this->getJson('/api/planillas')->assertForbidden();
    $this->postJson('/api/planillas', ['periodo' => '2026-09', 'fecha_pago' => '2026-09-30'])->assertForbidden();
    $this->getJson('/api/planilla-descuentos')->assertForbidden();
    $this->postJson('/api/planilla-descuentos', [
        'user_id' => $this->trabajador->id, 'tipo' => 'adelanto', 'concepto' => 'x',
        'monto' => 100, 'fecha_registro' => '2026-09-01',
    ])->assertForbidden();
});

it('anula a descuento but not one already saldado', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $pendiente = crearDescuento($this->administradorGeneral, $this->trabajador, 100.00);
    $saldado = crearDescuento($this->administradorGeneral, $this->trabajador, 50.00);
    PlanillaDescuento::findOrFail($saldado)->update(['estado' => 'saldado', 'pagado' => '50.00', 'saldo' => '0.00']);

    $this->postJson("/api/planilla-descuentos/{$pendiente}/anular")->assertSuccessful();
    expect(PlanillaDescuento::findOrFail($pendiente)->estado)->toBe('anulado');

    $this->postJson("/api/planilla-descuentos/{$saldado}/anular")->assertStatus(422);
});

it('rejects a descuento with an invalid tipo or a cuota greater than the monto', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);

    $this->postJson('/api/planilla-descuentos', [
        'user_id' => $this->trabajador->id, 'tipo' => 'inventado', 'concepto' => 'x',
        'monto' => 100, 'fecha_registro' => '2026-09-01',
    ])->assertUnprocessable()->assertJsonValidationErrors('tipo');

    $this->postJson('/api/planilla-descuentos', [
        'user_id' => $this->trabajador->id, 'tipo' => 'prestamo', 'concepto' => 'x',
        'monto' => 100, 'cuota' => 500, 'fecha_registro' => '2026-09-01',
    ])->assertUnprocessable()->assertJsonValidationErrors('cuota');
});
