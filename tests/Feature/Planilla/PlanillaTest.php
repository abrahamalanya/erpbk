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
        'en_planilla' => true,
        'sistema_pension' => 'onp',
        'seguro_salud' => 'essalud',
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

it('registers lineas with ONP 13% and EsSalud 9% on the sueldo básico, and freezes the totales', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1500.00]],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    // Mismo caso que la planilla de ejemplo: 1500 × 13% = 195 de ONP, neto
    // 1305; el empleador paga 1500 × 9% = 135 de EsSalud.
    expect($linea['onp'])->toBe('195.00')
        ->and($linea['afp'])->toBe('0.00')
        ->and($linea['tributos_trabajador'])->toBe('195.00')
        ->and($linea['neto'])->toBe('1305.00')
        ->and($linea['essalud_empleador'])->toBe('135.00')
        ->and($linea['sis'])->toBe('0.00')
        ->and($linea['aportes_empleador'])->toBe('135.00')
        ->and($linea['es_afp'])->toBeFalse()
        ->and($linea['es_sis'])->toBeFalse();

    expect($response->json('data.total_ingresos'))->toBe('1500.00')
        ->and($response->json('data.total_neto'))->toBe('1305.00');
});

it('registers AFP 11.37% and SIS S/ 15 for a trabajador in AFP with SIS', function () {
    $this->trabajador->update(['sistema_pension' => 'afp', 'seguro_salud' => 'sis']);
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1130.00]],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    // Planilla de ejemplo: 1130 × 11.37% = 128.48 → neto 1001.52; SIS 15.
    expect($linea['afp'])->toBe('128.48')
        ->and($linea['onp'])->toBe('0.00')
        ->and($linea['neto'])->toBe('1001.52')
        ->and($linea['sis'])->toBe('15.00')
        ->and($linea['essalud_empleador'])->toBe('0.00')
        ->and($linea['aportes_empleador'])->toBe('15.00')
        ->and($linea['es_afp'])->toBeTrue()
        ->and($linea['es_sis'])->toBeTrue();
});

it('calculates tributos and aportes on the sueldo básico, without bonificaciones', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00, 'bonificaciones' => 200.00]],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    // ONP 1000 × 13% = 130 y EsSalud 1000 × 9% = 90: la bonificación no entra.
    expect($linea['ingresos'])->toBe('1200.00')
        ->and($linea['sueldo_base'])->toBe('1000.00')
        ->and($linea['bonificaciones'])->toBe('200.00')
        ->and($linea['tributos_trabajador'])->toBe('130.00')
        ->and($linea['aportes_empleador'])->toBe('90.00')
        ->and($linea['neto'])->toBe('1070.00');
});

it('keeps the régimen of a closed line when the usuario changes it later', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful();
    $this->postJson("/api/planillas/{$planillaId}/cerrar")->assertSuccessful();

    $this->trabajador->update(['sistema_pension' => 'afp', 'seguro_salud' => 'sis']);

    $linea = collect($this->getJson("/api/planillas/{$planillaId}")->assertSuccessful()->json('data.trabajadores'))->first();

    expect($linea['es_afp'])->toBeFalse()
        ->and($linea['onp'])->toBe('130.00');
});

it('rejects a usuario that is not marked for planilla or has no régimen', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    $this->trabajador->update(['en_planilla' => false]);
    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertUnprocessable()->assertJsonPath('message', 'Norma Quispe no está marcado para planilla.');

    $this->trabajador->update(['en_planilla' => true, 'sistema_pension' => null]);
    $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertUnprocessable()->assertJsonPath('message', 'Registra el sistema de pensión (AFP/ONP) y el seguro (EsSalud/SIS) de Norma Quispe antes de agregarlo a la planilla.');
});

it('aplica el descuento pendiente del mes en la linea', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planillaId = crearPlanilla($this->administradorGeneral);

    // Adelanto de 100 sin cuota: se descuenta entero este mes.
    crearDescuento($this->administradorGeneral, $this->trabajador, 100.00);

    $response = $this->postJson("/api/planillas/{$planillaId}/lineas", [
        'lineas' => [['user_id' => $this->trabajador->id, 'ingresos' => 1000.00]],
    ])->assertSuccessful();

    $linea = collect($response->json('data.trabajadores'))->first();
    // 1000 - 100 (adelanto) - 130 (ONP) = 770
    expect($linea['descuentos_pagado'])->toBe('100.00')
        ->and($linea['neto'])->toBe('770.00');
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
