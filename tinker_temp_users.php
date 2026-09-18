<?php

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaService;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;

$admin = User::factory()->create([
    'nombre' => 'TempQA', 'apellido' => 'Admin', 'email' => 'tempqa.admin@example.test',
    'dni' => '99990001', 'password' => bcrypt('password123'), 'empresa_id' => 1, 'agencia_id' => 1,
]);
$admin->assignRole('administrador_agencia');

$asesor = User::factory()->create([
    'nombre' => 'TempQA', 'apellido' => 'Asesor', 'email' => 'tempqa.asesor@example.test',
    'dni' => '99990002', 'password' => bcrypt('password123'), 'empresa_id' => 1, 'agencia_id' => 1,
]);
$asesor->assignRole('asesor');

$conceptoIngreso = Concepto::firstOrCreate(['empresa_id' => 1, 'tipo' => 'ingreso', 'nombre' => 'Ingreso QA'], ['activo' => true]);
$conceptoGasto = Concepto::firstOrCreate(['empresa_id' => 1, 'tipo' => 'gasto', 'nombre' => 'Gasto QA'], ['activo' => true]);

app(CajaService::class)->aperturar($asesor);
$ciclo = Caja::where('user_id', $asesor->id)->firstOrFail()->cicloAbierto()->firstOrFail();

app(CajaService::class)->registrarMovimiento($asesor, 'ingreso', $conceptoIngreso->id, '500', null);
app(CajaService::class)->registrarMovimiento($asesor, 'egreso', $conceptoGasto->id, '40', null);

$cliente = Cliente::first() ?? Cliente::factory()->create(['empresa_id' => 1, 'agencia_id' => 1]);
$credito = Credito::first();

CajaMovimiento::query()->create([
    'caja_ciclo_id' => $ciclo->id, 'empresa_id' => 1, 'tipo' => 'egreso', 'monto' => 300,
    'concepto' => "Desembolso de crédito prendario #{$credito->id}", 'credito_id' => $credito->id,
    'registrado_por' => $asesor->id, 'fecha_caja' => $ciclo->fecha,
]);

$movimientoCobroEfectivo = CajaMovimiento::query()->create([
    'caja_ciclo_id' => $ciclo->id, 'empresa_id' => 1, 'tipo' => 'ingreso', 'monto' => 150,
    'concepto' => 'Cobro efectivo QA', 'registrado_por' => $asesor->id, 'fecha_caja' => $ciclo->fecha,
]);
Cobro::query()->create([
    'empresa_id' => 1, 'cliente_id' => $cliente->id, 'credito_id' => $credito?->id ?? 1,
    'caja_ciclo_id' => $ciclo->id, 'caja_movimiento_id' => $movimientoCobroEfectivo->id,
    'registrado_por' => $asesor->id, 'operacion' => 'refrendo', 'estado' => 'registrado',
    'credito_estado_anterior' => 'activo', 'monto_pagado' => 150, 'medio' => 'efectivo', 'interes' => 150,
]);

$movimientoCobroYape = CajaMovimiento::query()->create([
    'caja_ciclo_id' => $ciclo->id, 'empresa_id' => 1, 'tipo' => 'ingreso', 'monto' => 90,
    'concepto' => 'Cobro yape QA', 'registrado_por' => $asesor->id, 'fecha_caja' => $ciclo->fecha,
]);
Cobro::query()->create([
    'empresa_id' => 1, 'cliente_id' => $cliente->id, 'credito_id' => $credito?->id ?? 1,
    'caja_ciclo_id' => $ciclo->id, 'caja_movimiento_id' => $movimientoCobroYape->id,
    'registrado_por' => $asesor->id, 'operacion' => 'pago_cuotas_diario', 'estado' => 'registrado',
    'credito_estado_anterior' => 'activo', 'monto_pagado' => 90, 'medio' => 'yape', 'interes' => 90,
]);

$boveda = Boveda::where('agencia_id', 1)->first() ?? Boveda::first();
$billetaje = Billetaje::query()->create([
    'caja_ciclo_id' => $ciclo->id, 'boveda_id' => $boveda->id, 'empresa_id' => 1, 'monto' => 200,
    'estado' => 'aprobado', 'motivo' => 'Vuelto insuficiente para el día', 'medio_recepcion' => 'efectivo',
    'solicitado_por' => $asesor->id, 'aprobado_por' => $admin->id, 'fecha_resolucion' => now(),
]);
CajaMovimiento::query()->create([
    'caja_ciclo_id' => $ciclo->id, 'empresa_id' => 1, 'tipo' => 'billetaje', 'monto' => 200,
    'billetaje_id' => $billetaje->id, 'registrado_por' => $asesor->id, 'fecha_caja' => $ciclo->fecha,
]);

app(CajaService::class)->cerrar($asesor, '900');

echo "admin_id={$admin->id} asesor_id={$asesor->id} ciclo_id={$ciclo->id}" . PHP_EOL;
