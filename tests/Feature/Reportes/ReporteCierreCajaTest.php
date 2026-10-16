<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->ciclo = Caja::query()->where('user_id', $this->asesor->id)->firstOrFail()->cicloAbierto()->firstOrFail();
    $this->url = "/api/reportes/cajas-apertura-cierre/{$this->ciclo->id}/reporte";
    $this->cliente = Cliente::factory()->create(['empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    $this->credito = Credito::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        'cliente_id' => $this->cliente->id, 'registrado_por' => $this->asesor->id,
    ]);
    $this->movimiento = fn (array $datos): CajaMovimiento => CajaMovimiento::query()->create([
        'empresa_id' => $this->empresa->id, 'caja_ciclo_id' => $this->ciclo->id,
        'registrado_por' => $this->asesor->id, 'fecha_caja' => $this->ciclo->fecha,
        'medio' => 'efectivo', ...$datos,
    ]);
    $this->cobro = function (string $medio, string $monto): Cobro {
        $movimiento = ($this->movimiento)(['tipo' => 'ingreso', 'monto' => $monto, 'medio' => $medio]);

        return Cobro::factory()->create([
            'empresa_id' => $this->empresa->id, 'cliente_id' => $this->cliente->id,
            'credito_id' => $this->credito->id, 'registrado_por' => $this->asesor->id,
            'caja_ciclo_id' => $this->ciclo->id, 'caja_movimiento_id' => $movimiento->id,
            'medio' => $medio, 'monto_pagado' => $monto, 'estado' => 'registrado', 'operacion' => 'pago_cuotas_diario',
            'credito_estado_anterior' => 'activo',
        ]);
    };
});

it('reports payments, disbursements and grouped expenses without counting payments twice', function () {
    $this->ciclo->update(['saldo_apertura' => '50.00']);
    ($this->movimiento)(['tipo' => 'billetaje', 'monto' => '80.00']);
    ($this->movimiento)(['tipo' => 'ingreso', 'monto' => '100.00', 'concepto' => 'Ingreso adicional']);
    ($this->movimiento)(['tipo' => 'ingreso', 'monto' => '10.00', 'concepto' => 'Venta', 'concepto_id' => null]);
    $gasto = ($this->movimiento)(['tipo' => 'egreso', 'monto' => '30.00', 'concepto' => 'Moto', 'serie' => 'B001', 'numero' => '123', 'descripcion' => 'Combustible']);
    $gasto->fotos()->create(['tipo' => 'comprobante', 'path' => 'gastos/foto.jpg', 'orden' => 0]);
    ($this->movimiento)(['tipo' => 'egreso', 'monto' => '5.00', 'concepto' => ' moto ']);
    ($this->movimiento)(['tipo' => 'egreso', 'monto' => '150.00', 'credito_id' => $this->credito->id]);
    ($this->cobro)('efectivo', '200.00');
    ($this->cobro)('yape', '25.00');
    ($this->cobro)('plin', '15.00');
    ($this->cobro)('transferencia', '10.00');
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 250])->assertSuccessful();

    $this->getJson($this->url)->assertSuccessful()
        ->assertJsonPath('data.cierre.estado', 'cerrada')
        ->assertJsonPath('data.usuario.id', $this->asesor->id)
        ->assertJsonPath('data.agencia.id', $this->agencia->id)
        ->assertJsonPath('data.resumen.saldo_apertura', '50.00')
        ->assertJsonPath('data.resumen.total_billetaje', '80.00')
        ->assertJsonPath('data.resumen.total_cobrado', '250.00')
        ->assertJsonPath('data.resumen.total_ingresos', '110.00')
        ->assertJsonPath('data.resumen.total_desembolsos', '150.00')
        ->assertJsonPath('data.resumen.total_gastos', '35.00')
        ->assertJsonPath('data.resumen.saldo_total', '305.00')
        ->assertJsonPath('data.resumen.saldo_efectivo', '255.00')
        ->assertJsonPath('data.resumen.monto_contado', '250.00')
        ->assertJsonPath('data.resumen.diferencia', '-5.00')
        ->assertJsonPath('data.resumen.faltante', '5.00')
        ->assertJsonPath('data.resumen.sobrante', '0.00')
        ->assertJsonPath('data.resumen.neto', '300.00')
        ->assertJsonPath('data.totales_por_medio.efectivo', '200.00')
        ->assertJsonPath('data.totales_por_medio.yape', '25.00')
        ->assertJsonPath('data.totales_por_medio.plin', '15.00')
        ->assertJsonPath('data.totales_por_medio.transferencia', '10.00')
        ->assertJsonPath('data.pagos_por_medio.efectivo.0.cliente.id', $this->cliente->id)
        ->assertJsonPath('data.pagos_por_medio.efectivo.0.estado_credito_al_cobrar', 'activo')
        ->assertJsonPath('data.desembolsos.0.credito_codigo', $this->credito->codigo)
        ->assertJsonCount(1, 'data.gastos_por_concepto')
        ->assertJsonPath('data.gastos_por_concepto.0.total', '35.00')
        ->assertJsonPath('data.gastos_por_concepto.0.cantidad', 2)
        ->assertJsonPath('data.gastos.0.serie', 'B001')
        ->assertJsonPath('data.gastos.0.observacion', 'Combustible')
        ->assertJsonPath('data.gastos.0.comprobante_url', $gasto->fotos->first()->url);

    $this->getJson('/api/reportes/cajas-apertura-cierre')->assertSuccessful()
        ->assertJsonPath('data.0.total_ingresos', '110.00')
        ->assertJsonPath('data.0.total_egresos', '35.00')
        ->assertJsonPath('data.0.total_desembolso', '150.00');
    $this->getJson("/api/reportes/cajas-apertura-cierre/{$this->ciclo->id}/detalle")->assertSuccessful()
        ->assertJsonPath('data.total_ingresos', '110.00')
        ->assertJsonPath('data.total_egresos', '35.00')
        ->assertJsonPath('data.total_desembolso', '150.00');

    $this->get($this->url.'/pdf')->assertSuccessful()->assertHeader('content-type', 'application/pdf');
});

it('keeps empty open cycles usable before counting cash', function () {
    $this->getJson($this->url)->assertSuccessful()
        ->assertJsonPath('data.cierre.estado', 'abierta')
        ->assertJsonPath('data.resumen.saldo_total', '0.00')
        ->assertJsonPath('data.resumen.monto_contado', null)
        ->assertJsonPath('data.resumen.diferencia', null)
        ->assertJsonPath('data.totales_por_medio.efectivo', '0.00')
        ->assertJsonCount(0, 'data.gastos');
});

it('excludes cancelled payments and operations belonging to other cycles', function () {
    $cancelado = ($this->cobro)('efectivo', '90.00');
    $cancelado->update(['estado' => 'anulado']);
    $cancelado->cajaMovimiento->delete();
    ($this->cobro)('efectivo', '0.10');
    ($this->cobro)('efectivo', '0.20');
    $this->postJson('/api/caja/cerrar', ['monto_contado' => '0.30'])->assertSuccessful();
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $otroCiclo = Caja::query()->where('user_id', $this->asesor->id)->firstOrFail()->cicloAbierto()->firstOrFail();
    CajaMovimiento::query()->create([
        'empresa_id' => $this->empresa->id, 'caja_ciclo_id' => $otroCiclo->id,
        'registrado_por' => $this->asesor->id, 'fecha_caja' => $otroCiclo->fecha,
        'tipo' => 'ingreso', 'monto' => '999.00',
    ]);
    $this->getJson($this->url)->assertSuccessful()
        ->assertJsonPath('data.resumen.total_cobrado', '0.30')
        ->assertJsonPath('data.resumen.total_ingresos', '0.00')
        ->assertJsonPath('data.resumen.saldo_total', '0.30')
        ->assertJsonCount(2, 'data.pagos_por_medio.efectivo');
});

it('compares counted cash against physical cash instead of the total including digital payments', function () {
    ($this->cobro)('efectivo', '100.00');
    ($this->cobro)('yape', '50.00');
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 110])->assertSuccessful();
    $this->getJson($this->url)->assertSuccessful()
        ->assertJsonPath('data.resumen.saldo_total', '150.00')
        ->assertJsonPath('data.resumen.saldo_efectivo', '100.00')
        ->assertJsonPath('data.resumen.sobrante', '10.00')
        ->assertJsonPath('data.resumen.faltante', '0.00')
        ->assertJsonPath('data.resumen.neto', '160.00');
});

it('allows the general administrator to read a caja report', function () {
    $admin = User::factory()->forEmpresa($this->empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);
    $this->getJson($this->url)->assertSuccessful();
});

it('denies reports outside the permitted scope', function (string $rol, bool $otraEmpresa, bool $otraAgencia) {
    $empresa = $otraEmpresa ? Empresa::factory()->create() : $this->empresa;
    $agencia = $otraAgencia || $otraEmpresa ? Agencia::factory()->for($empresa)->create() : $this->agencia;
    $actor = User::factory()->forAgencia($agencia)->create();
    $actor->assignRole($rol);
    Sanctum::actingAs($actor, ['*']);
    $expected = $otraEmpresa ? 404 : 403;
    $this->getJson($this->url)->assertStatus($expected);
    $this->getJson($this->url.'/pdf')->assertStatus($expected);
})->with([
    'another asesor in the same agency' => ['asesor', false, false],
    'administrator of another agency' => ['administrador_agencia', false, true],
    'administrator of another company' => ['administrador_general', true, true],
    'role without cajas.ver' => ['peinadora', false, false],
]);

it('requires authentication', function () {
    auth()->forgetGuards();
    $this->getJson($this->url)->assertUnauthorized();
});

it('classifies credit arrears at the closing date', function (int $dias, string $estado) {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $this->credito->update(['fecha_vencimiento' => now()->subDays($dias)->toDateString()]);
    ($this->cobro)('efectivo', '20.00');
    $this->getJson($this->url)->assertSuccessful()
        ->assertJsonPath('data.pagos_por_medio.efectivo.0.estado_credito', $estado)
        ->assertJsonPath('data.pagos_por_medio.efectivo.0.dias_atraso', $dias);
})->with([
    [0, 'al_dia'], [1, 'atraso_leve'], [3, 'atraso_leve'],
    [4, 'atraso'], [15, 'atraso'], [16, 'mora'],
]);

it('does not treat a later liquidation as part of an earlier closing report', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $this->credito->update(['fecha_vencimiento' => now()->subDays(2)->toDateString()]);
    $pago = ($this->cobro)('efectivo', '20.00');
    $pago->update(['operacion' => 'pago_cuotas_diario']);
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 20])->assertSuccessful();
    $this->travel(3)->days();
    $this->credito->update(['estado' => 'liquidado']);
    Cobro::factory()->create([
        'empresa_id' => $this->empresa->id, 'cliente_id' => $this->cliente->id,
        'credito_id' => $this->credito->id, 'registrado_por' => $this->asesor->id,
        'operacion' => 'liquidacion', 'estado' => 'registrado', 'monto_pagado' => 500,
    ]);
    $this->getJson($this->url)->assertSuccessful()
        ->assertJsonPath('data.pagos_por_medio.efectivo.0.estado_credito', 'atraso_leve')
        ->assertJsonPath('data.pagos_por_medio.efectivo.0.dias_atraso', 2);
});

it('uses the business date of the cycle even when the closing instant is on the next UTC day', function () {
    $this->travelTo(Carbon::parse('2026-10-15 23:30:00', 'America/Lima'));
    $this->ciclo->update(['fecha' => '2026-10-15']);
    $this->credito->update(['fecha_vencimiento' => '2026-10-14']);
    ($this->cobro)('efectivo', '20.00');
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 20])->assertSuccessful();
    $this->getJson($this->url)->assertSuccessful()
        ->assertJsonPath('data.pagos_por_medio.efectivo.0.dias_atraso', 1)
        ->assertJsonPath('data.cierre.hora_cierre', '23:30');
});

it('marks a credit liquidated when the liquidation occurred before closing', function () {
    $pago = ($this->cobro)('efectivo', '100.00');
    $pago->update(['operacion' => 'liquidacion']);
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 100])->assertSuccessful();
    $this->getJson($this->url)->assertSuccessful()
        ->assertJsonPath('data.pagos_por_medio.efectivo.0.estado_credito', 'liquidado');
});
