<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
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

    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'plazo_dias' => 30, 'dias_espera_mora' => 15, 'dias_minimo_interes' => 15,
        'tasa_mora_diaria' => 1, 'max_refrendos' => null,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro']);

    $this->caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    $this->ciclo = CajaCiclo::query()->create([
        'caja_id' => $this->caja->id, 'empresa_id' => $this->caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);
});

it('sums the monto and counts the cobros of the filtered universe, excluding anulados', function () {
    $credito = Credito::factory()->paraBien($this->bien)
        ->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $total = (float) $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $cobro = Cobro::query()->where('credito_id', $credito->id)->firstOrFail();

    $response = $this->getJson('/api/cobros/resumen')->assertSuccessful();
    expect($response->json('data.cantidad'))->toBe(1)
        ->and(round((float) $response->json('data.total'), 2))->toBe(round($total, 2));

    // Un cobro anulado ya no es cobranza: no cuenta ni en el total ni en la cantidad.
    $this->postJson("/api/cobros/{$cobro->id}/anular", [])->assertSuccessful();

    $response = $this->getJson('/api/cobros/resumen')->assertSuccessful();
    expect($response->json('data.cantidad'))->toBe(0)
        ->and((float) $response->json('data.total'))->toBe(0.0);
});

it('scopes the resumen by the same filters as the listing', function () {
    $otroCliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $otroBien = Bien::factory()->paraCliente($otroCliente)->create(['tipo' => 'electro']);

    $creditoA = Credito::factory()->paraBien($this->bien)->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $this->cliente->id]);
    $creditoB = Credito::factory()->paraBien($otroBien)->activo()
        ->create(['registrado_por' => $this->asesor->id, 'cliente_id' => $otroCliente->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    foreach ([$creditoA, $creditoB] as $credito) {
        $total = $this->getJson("/api/creditos-prendarios/{$credito->id}")->json('data.monto_liquidacion_sugerido.total');
        $this->postJson("/api/creditos-prendarios/{$credito->id}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
            ->assertSuccessful();
    }

    $this->getJson('/api/cobros/resumen')->assertSuccessful()->assertJsonPath('data.cantidad', 2);

    $this->getJson("/api/cobros/resumen?cliente_id={$this->cliente->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.cantidad', 1);
});
