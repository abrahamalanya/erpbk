<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Planilla\Models\ConfiguracionPlanilla;
use App\Modules\Planilla\Models\Planilla;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');

    $this->trabajador = User::factory()->forAgencia($this->agencia)->create([
        'nombre' => 'Norma',
        'apellido' => 'Quispe',
        'dni' => '74901226',
        'en_planilla' => true,
        'sistema_pension' => 'afp',
        'seguro_salud' => 'essalud',
    ]);
    $this->trabajador->assignRole('asesor');
});

/** Crea una planilla del periodo con una línea y la cierra. */
function planillaCerrada(User $actor, User $trabajador, string $periodo = '2026-09'): Planilla
{
    $id = test()->postJson('/api/planillas', [
        'periodo' => $periodo,
        'fecha_pago' => '2026-09-30',
    ])->assertCreated()->json('data.id');

    test()->postJson("/api/planillas/{$id}/lineas", [
        'lineas' => [['user_id' => $trabajador->id, 'ingresos' => 1130.00]],
    ])->assertSuccessful();

    test()->postJson("/api/planillas/{$id}/cerrar")->assertSuccessful();

    return Planilla::findOrFail($id);
}

it('lists the planillas del actor with their totales', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    planillaCerrada($this->administradorGeneral, $this->trabajador);

    $this->getJson('/api/reportes/planilla')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.periodo', '2026-09')
        ->assertJsonPath('data.0.total_ingresos', '1130.00');
});

it('builds the reporte in the PLAME R01 format', function () {
    $this->empresa->update(['ruc' => '20602137903', 'razon_social' => 'CREDIMAS ORIENTE E.I.R.L']);
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planilla = planillaCerrada($this->administradorGeneral, $this->trabajador);

    $reporte = $this->getJson("/api/reportes/planilla/{$planilla->id}")->assertSuccessful()->json('data');

    expect($reporte['ruc'])->toBe('20602137903')
        ->and($reporte['empleador'])->toBe('CREDIMAS ORIENTE E.I.R.L')
        ->and($reporte['periodo_plame'])->toBe('09/2026');

    // Primera fila de la planilla de ejemplo: 1130 de sueldo, AFP 11.37% =
    // 128.48, neto 1001.52, EsSalud 9% = 101.70.
    $fila = $reporte['trabajadores'][0];
    expect($fila['tipo_documento'])->toBe('01')
        ->and($fila['numero_documento'])->toBe('74901226')
        ->and($fila['apellidos'])->toBe('QUISPE')
        ->and($fila['nombres'])->toBe('NORMA')
        ->and($fila['situacion'])->toBe('ACTIVO O SUBSIDIADO')
        ->and($fila['ingresos_devengado'])->toBe('1130.00')
        ->and($fila['ingresos_pagado'])->toBe('1130.00')
        ->and($fila['descuentos'])->toBe('0.00')
        ->and($fila['tributos_trabajador'])->toBe('128.48')
        ->and($fila['neto'])->toBe('1001.52')
        ->and($fila['aportes_empleador'])->toBe('101.70');

    expect($reporte['totales'])->toBe([
        'ingresos_devengado' => '1130.00',
        'ingresos_pagado' => '1130.00',
        'descuentos' => '0.00',
        'tributos_trabajador' => '128.48',
        'neto' => '1001.52',
        'aportes_empleador' => '101.70',
    ]);
});

it('filters the listado by periodo', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    planillaCerrada($this->administradorGeneral, $this->trabajador, '2026-09');
    planillaCerrada($this->administradorGeneral, $this->trabajador, '2026-08');

    $this->getJson('/api/reportes/planilla?periodo=2026-09')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.periodo', '2026-09');
});

it('exports the reporte to PDF and Excel', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planilla = planillaCerrada($this->administradorGeneral, $this->trabajador);

    $this->get("/api/reportes/planilla/{$planilla->id}/pdf")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/pdf');

    $this->get("/api/reportes/planilla/{$planilla->id}/excel")
        ->assertSuccessful()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('denies the reporte to a role without the planillas permissions', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planilla = planillaCerrada($this->administradorGeneral, $this->trabajador);

    // El asesor no puede ni ver el listado ni el detalle de una planilla.
    Sanctum::actingAs($this->trabajador, ['*']);
    $this->getJson('/api/reportes/planilla')->assertForbidden();
    $this->getJson("/api/reportes/planilla/{$planilla->id}")->assertForbidden();
});

it('a closed planilla keeps reporting the amounts it was closed with', function () {
    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $planilla = planillaCerrada($this->administradorGeneral, $this->trabajador);

    $antes = $this->getJson("/api/reportes/planilla/{$planilla->id}")->assertSuccessful()->json('data.trabajadores.0.tributos_trabajador');

    // Cambiar las tasas no debe alterar un mes ya liquidado: los importes
    // están congelados en la línea.
    ConfiguracionPlanilla::query()
        ->where('empresa_id', $this->empresa->id)
        ->update(['afp_trabajador' => 20, 'essalud_empleador' => 20]);

    $despues = $this->getJson("/api/reportes/planilla/{$planilla->id}")->assertSuccessful()->json('data.trabajadores.0.tributos_trabajador');
    expect($despues)->toBe($antes);
});
