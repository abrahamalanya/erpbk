<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoHipotecario\Models\Inmueble;
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
        'tipo_credito' => 'hipotecario', 'plazo_dias' => 360, 'dias_espera_mora' => 30,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05,
    ]);
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create(['tipo_credito' => 'prendario']);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->inmueble = Inmueble::factory()->paraCliente($this->cliente)->create(['valorizacion' => 150000]);
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create();
});

function creditoHipotecarioVencido($test): Credito
{
    return Credito::factory()->paraInmueble($test->inmueble)->vencido(10)->create([
        'monto_prestamo' => 1000, 'interes' => 15,
        'registrado_por' => $test->asesor->id, 'empresa_id' => $test->empresa->id, 'agencia_id' => $test->agencia->id,
    ]);
}

it('rejects refinanciar on a non-hipotecario crédito', function () {
    $prendario = Credito::factory()->paraBien($this->bien)->activo()->create([
        'registrado_por' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$prendario->id}/refinanciar", ['medio' => 'efectivo'])
        ->assertUnprocessable();
});

it('refinancia el 100% de la deuda (capital + interés + mora) sin exigir caja aperturada cuando no se paga nada ahora', function () {
    $credito = creditoHipotecarioVencido($this);

    Sanctum::actingAs($this->asesor, ['*']);
    // capital 1000 + interés (1000*15*40/3000=200) + mora (1000*0.0005*10=5) = 1205, sin caja aperturada.
    $response = $this->postJson("/api/creditos-prendarios/{$credito->id}/refinanciar", [
        'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.monto_prestamo'))->toBe('1205.00')
        ->and($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.refinanciamiento_de_credito_id'))->toBe($credito->id);

    expect($credito->fresh()->estado)->toBe('refinanciado');

    $cobro = Cobro::query()->where('credito_id', $credito->id)->latest()->first();
    expect((string) $cobro->monto_pagado)->toBe('0.00')
        ->and($cobro->caja_ciclo_id)->toBeNull()
        ->and($cobro->operacion)->toBe('refinanciamiento');
});

it('permite pagar una parte ahora y refinanciar solo la diferencia, exigiendo caja aperturada', function () {
    $credito = creditoHipotecarioVencido($this);

    // Sin caja aperturada, pagar algo ahora debe fallar.
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$credito->id}/refinanciar", [
        'monto_pagado' => 500, 'medio' => 'efectivo',
    ])->assertUnprocessable();

    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);

    // deuda total 1205, paga 500 -> nuevo capital = 705.
    $response = $this->postJson("/api/creditos-prendarios/{$credito->id}/refinanciar", [
        'monto_pagado' => 500, 'medio' => 'efectivo',
    ])->assertCreated();

    expect($response->json('data.monto_prestamo'))->toBe('705.00');

    $cobro = Cobro::query()->where('credito_id', $credito->id)->latest()->first();
    expect((string) $cobro->monto_pagado)->toBe('500.00')
        ->and($cobro->caja_ciclo_id)->not->toBeNull();
});

it('rechaza refinanciar cuando el monto pagado cubre el total de la deuda', function () {
    $credito = creditoHipotecarioVencido($this);
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 0, 'abierta_at' => now(),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson("/api/creditos-prendarios/{$credito->id}/refinanciar", [
        'monto_pagado' => 1205, 'medio' => 'efectivo',
    ])->assertUnprocessable();
});

it('aplica un descuento al refinanciar, reduciendo el nuevo capital', function () {
    $credito = creditoHipotecarioVencido($this);

    Sanctum::actingAs($this->asesor, ['*']);

    // deuda total 1205, descuento 5 (la mora) -> nuevo capital = 1200.
    $response = $this->postJson("/api/creditos-prendarios/{$credito->id}/refinanciar", [
        'medio' => 'efectivo', 'descuento' => 5, 'motivo_descuento' => 'condonación de mora',
    ])->assertCreated();

    expect($response->json('data.monto_prestamo'))->toBe('1200.00');

    $cobro = Cobro::query()->where('credito_id', $credito->id)->latest()->first();
    expect((string) $cobro->descuento)->toBe('5.00')
        ->and($cobro->motivo_descuento)->toBe('condonación de mora');
});
