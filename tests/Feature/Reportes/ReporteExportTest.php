<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Cubre solo los 6 endpoints de export (PDF/Excel) de los 3 reportes —
 * status, Content-Type y permisos. El contenido de cada reporte (filtros,
 * cálculo de filas) ya está cubierto en ReporteCobranzaTest,
 * ReporteMovimientosDineroTest y CobranzaTest; acá basta con datos vacíos,
 * las vistas/generadores ya manejan "sin filas" (@empty en reportes.tabla).
 */
beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->adminGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->adminGeneral->assignRole('administrador_general');

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
});

it('exports cobranza diaria as pdf and excel', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/reportes/cobranza-diaria/pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->getJson('/api/reportes/cobranza-diaria/excel')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('denies exporting cobranza diaria to a user without creditos_prendarios.ver', function () {
    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($sinPermiso, ['*']);
    $this->getJson('/api/reportes/cobranza-diaria/pdf')->assertForbidden();
    $this->getJson('/api/reportes/cobranza-diaria/excel')->assertForbidden();
});

it('exports movimientos de dinero as pdf and excel', function () {
    Sanctum::actingAs($this->adminGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();

    $this->getJson('/api/reportes/movimientos-dinero/pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->getJson('/api/reportes/movimientos-dinero/excel')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('denies exporting movimientos de dinero to a user without bovedas.ver', function () {
    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($sinPermiso, ['*']);
    $this->getJson('/api/reportes/movimientos-dinero/pdf')->assertForbidden();
    $this->getJson('/api/reportes/movimientos-dinero/excel')->assertForbidden();
});

it('exports cobros as pdf and excel', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/cobros/pdf')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->getJson('/api/cobros/excel')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('denies exporting cobros to a user without cobranzas.ver', function () {
    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();
    $sinPermiso->assignRole('secretaria');

    Sanctum::actingAs($sinPermiso, ['*']);
    $this->getJson('/api/cobros/pdf')->assertForbidden();
    $this->getJson('/api/cobros/excel')->assertForbidden();
});
