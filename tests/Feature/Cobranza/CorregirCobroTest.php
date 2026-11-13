<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();
    $this->asesor = User::factory()->forAgencia($agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->admin = User::factory()->forEmpresa($empresa)->create();
    $this->admin->assignRole('administrador_general');
    $cliente = Cliente::factory()->forAgencia($agencia)->create();
    $this->credito = Credito::factory()->diario()->activo()->create([
        'empresa_id' => $empresa->id, 'agencia_id' => $agencia->id,
        'registrado_por' => $this->asesor->id, 'cliente_id' => $cliente->id,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($empresa)->create(['tipo_credito' => 'diario', 'tasa_mora_diaria' => 0]);
    $caja = Caja::factory()->create(['empresa_id' => $empresa->id, 'agencia_id' => $agencia->id, 'user_id' => $this->asesor->id]);
    $this->ayer = CajaCiclo::query()->create([
        'empresa_id' => $empresa->id, 'caja_id' => $caja->id, 'fecha' => today()->subDay(),
        'estado' => 'cerrada', 'saldo_apertura' => 0, 'saldo_arqueo_cierre' => 12,
    ]);
    $this->hoy = CajaCiclo::query()->create([
        'empresa_id' => $empresa->id, 'caja_id' => $caja->id, 'fecha' => today(), 'estado' => 'abierta', 'saldo_apertura' => 0,
    ]);
    $movimiento = CajaMovimiento::query()->create([
        'empresa_id' => $empresa->id, 'caja_ciclo_id' => $this->ayer->id, 'tipo' => 'ingreso',
        'monto' => 12, 'medio' => 'efectivo', 'registrado_por' => $this->asesor->id, 'fecha_caja' => $this->ayer->fecha,
    ]);
    $this->cobro = Cobro::factory()->create([
        'empresa_id' => $empresa->id, 'cliente_id' => $cliente->id, 'credito_id' => $this->credito->id,
        'registrado_por' => $this->asesor->id, 'caja_ciclo_id' => $this->ayer->id, 'caja_movimiento_id' => $movimiento->id,
        'operacion' => 'pago_cuotas_diario', 'estado' => 'registrado', 'monto_pagado' => 12, 'interes' => 12,
        'created_at' => now()->subDay(),
    ]);
    $this->cuota = CuotaCredito::query()->create([
        'credito_id' => $this->credito->id, 'empresa_id' => $empresa->id, 'numero_cuota' => 1,
        'monto_capital' => 90, 'monto_interes' => 10,
        'monto_total' => 100, 'monto_abonado' => 12, 'fecha_vencimiento' => today()->addDays(5),
    ]);
    $this->cobro->abonosCuotas()->create(['cuota_credito_id' => $this->cuota->id, 'monto_mora' => 0, 'monto_cuota' => 12, 'completa' => false]);
    $this->datos = ['monto_pagado' => '12.00', 'mover_a_hoy' => true, 'motivo' => 'Cobro guardado en ciclo anterior', 'updated_at' => $this->cobro->updated_at->toISOString()];
    Sanctum::actingAs($this->admin);
});

it('moves the same collection and movement to todays cycle without changing author or collection date', function () {
    $fecha = $this->cobro->created_at->toISOString();
    $this->patchJson('/api/cobros/'.$this->cobro->id, $this->datos)->assertSuccessful();
    expect($this->cobro->fresh()->caja_ciclo_id)->toBe($this->hoy->id)
        ->and($this->cobro->fresh()->registrado_por)->toBe($this->asesor->id)
        ->and($this->cobro->fresh()->created_at->toISOString())->toBe($fecha)
        ->and($this->ayer->saldoActual())->toBe('0.00')
        ->and($this->hoy->saldoActual())->toBe('12.00')
        ->and($this->ayer->fresh()->saldo_calculado_cierre)->toBe('0.00')
        ->and(DB::table('cobro_correcciones')->count())->toBe(1);
    expect($this->cobro->fresh()->cajaMovimiento->fecha_caja->toDateString())->toBe(today()->toDateString());
});

it('changes amounts and allocations together and rejects stale edits', function () {
    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$this->datos, 'monto_pagado' => '20.50'])->assertSuccessful();
    expect($this->cuota->fresh()->monto_abonado)->toBe('20.50')
        ->and($this->cobro->fresh()->monto_pagado)->toBe('20.50')
        ->and($this->hoy->saldoActual())->toBe('20.50')
        ->and($this->cobro->abonosCuotas()->count())->toBe(1);
    $this->patchJson('/api/cobros/'.$this->cobro->id, $this->datos)->assertConflict();
});

it('requires the administrators role', function () {
    Sanctum::actingAs($this->asesor);
    $this->patchJson('/api/cobros/'.$this->cobro->id, $this->datos)->assertForbidden();
});

it('can lower an amount in its original cycle and uses the original payment date for late fees', function () {
    ConfiguracionCredito::query()->where('empresa_id', $this->credito->empresa_id)->update(['tasa_mora_diaria' => 1]);
    $this->cuota->update(['fecha_vencimiento' => $this->cobro->created_at->toDateString()]);
    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$this->datos, 'monto_pagado' => '8.50', 'mover_a_hoy' => false])->assertSuccessful();
    expect($this->cuota->fresh()->monto_abonado)->toBe('8.50')
        ->and($this->cobro->fresh()->mora)->toBe('0.00')
        ->and($this->ayer->saldoActual())->toBe('8.50')
        ->and($this->hoy->saldoActual())->toBe('0.00');
});

it('refuses editing an earlier payment after another collection touched its installment', function () {
    $posterior = $this->cobro->replicate();
    $posterior->save();
    $posterior->abonosCuotas()->create(['cuota_credito_id' => $this->cuota->id, 'monto_mora' => 0, 'monto_cuota' => 5, 'completa' => false]);
    $this->cuota->update(['monto_abonado' => 17]);
    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$this->datos, 'monto_pagado' => '8.50'])->assertUnprocessable();
    expect($this->cuota->fresh()->monto_abonado)->toBe('17.00')
        ->and($this->cobro->fresh()->monto_pagado)->toBe('12.00');
});

it('does not expose another companys collection to an administrator', function () {
    $otro = User::factory()->forEmpresa(Empresa::factory()->create())->create();
    $otro->assignRole('administrador_general');
    Sanctum::actingAs($otro);
    $this->patchJson('/api/cobros/'.$this->cobro->id, $this->datos)->assertNotFound();
});

it('requires an open destination cycle and leaves all records untouched', function () {
    $this->hoy->update(['estado' => 'cerrada']);
    $this->patchJson('/api/cobros/'.$this->cobro->id, $this->datos)->assertUnprocessable();
    expect($this->cobro->fresh()->caja_ciclo_id)->toBe($this->ayer->id)
        ->and(DB::table('cobro_correcciones')->count())->toBe(0);
});

it('rolls back allocations if the origin would become negative', function () {
    CajaMovimiento::query()->create([
        'empresa_id' => $this->credito->empresa_id, 'caja_ciclo_id' => $this->ayer->id,
        'tipo' => 'egreso', 'monto' => 10, 'registrado_por' => $this->asesor->id,
        'fecha_caja' => $this->ayer->fecha,
    ]);
    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$this->datos, 'monto_pagado' => '20'])->assertUnprocessable();
    expect($this->cuota->fresh()->monto_abonado)->toBe('12.00')
        ->and($this->cobro->fresh()->caja_ciclo_id)->toBe($this->ayer->id)
        ->and(DB::table('cobro_correcciones')->count())->toBe(0);
});

it('rejects invalid amounts and a missing reason', function () {
    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$this->datos, 'monto_pagado' => -1, 'motivo' => ''])->assertUnprocessable();
});

it('moves a collection from November 11 to the closed November 12 cycle when today is November 13', function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00:00'));
    $this->ayer->update(['fecha' => '2026-11-11']);
    $this->hoy->update(['fecha' => '2026-11-12', 'estado' => 'cerrada', 'saldo_arqueo_cierre' => 12]);
    $datos = $this->datos;
    unset($datos['mover_a_hoy']);

    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$datos, 'caja_ciclo_id' => $this->hoy->id])->assertSuccessful();

    expect($this->cobro->fresh()->caja_ciclo_id)->toBe($this->hoy->id)
        ->and($this->cobro->fresh()->cajaMovimiento->fecha_caja->toDateString())->toBe('2026-11-12')
        ->and($this->ayer->fresh()->saldo_calculado_cierre)->toBe('0.00')
        ->and($this->hoy->fresh()->saldo_calculado_cierre)->toBe('12.00')
        ->and($this->hoy->fresh()->saldo_arqueo_cierre)->toBe('12.00')
        ->and($this->hoy->fresh()->diferencia)->toBe('0.00')
        ->and($this->hoy->fresh()->estado)->toBe('cerrada');
    $auditoria = DB::table('cobro_correcciones')->first();
    expect(json_decode($auditoria->despues, true)['caja_ciclo_id'])->toBe($this->hoy->id);
});

it('lists only past and present cycles of the collection owners cashbox', function () {
    CajaCiclo::query()->create([
        'empresa_id' => $this->credito->empresa_id, 'caja_id' => $this->ayer->caja_id,
        'fecha' => today()->addDay(), 'estado' => 'cerrada', 'saldo_apertura' => 0,
    ]);
    $otroAsesor = User::factory()->forAgencia($this->asesor->agencia)->create();
    $otraCaja = Caja::factory()->create(['empresa_id' => $this->credito->empresa_id, 'agencia_id' => $this->credito->agencia_id, 'user_id' => $otroAsesor->id]);
    $otroCiclo = CajaCiclo::query()->create([
        'empresa_id' => $this->credito->empresa_id, 'caja_id' => $otraCaja->id,
        'fecha' => today(), 'estado' => 'abierta', 'saldo_apertura' => 0,
    ]);
    $this->getJson('/api/cobros/'.$this->cobro->id.'/ciclos-destino')->assertSuccessful()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $this->hoy->id)
        ->assertJsonPath('data.1.id', $this->ayer->id);
    $datos = $this->datos;
    unset($datos['mover_a_hoy']);
    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$datos, 'caja_ciclo_id' => $otroCiclo->id])->assertUnprocessable();
    expect($this->cobro->fresh()->caja_ciclo_id)->toBe($this->ayer->id);

    Sanctum::actingAs($this->asesor);
    $this->getJson('/api/cobros/'.$this->cobro->id.'/ciclos-destino')->assertForbidden();
});

it('requires a real destination cycle and rejects future cycles', function () {
    $datos = $this->datos;
    unset($datos['mover_a_hoy']);
    $this->patchJson('/api/cobros/'.$this->cobro->id, $datos)->assertUnprocessable();
    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$datos, 'caja_ciclo_id' => 999999])->assertUnprocessable();
    $this->hoy->update(['fecha' => today()->addDay()]);
    $this->patchJson('/api/cobros/'.$this->cobro->id, [...$datos, 'caja_ciclo_id' => $this->hoy->id])->assertUnprocessable();
    expect(DB::table('cobro_correcciones')->count())->toBe(0);
});
