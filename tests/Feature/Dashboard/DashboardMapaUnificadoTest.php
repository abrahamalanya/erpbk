<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ruta\Models\VisitaCliente;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->ubicacion = UbicacionAsesor::query()->create([
        'user_id' => $this->asesor->id, 'latitud' => -8.0, 'longitud' => -74.0,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);
});

/** Crédito diario vencido ayer (en mora, entra a la ruta de hoy) para $cliente. */
function creditoEnMoraParaMapa(Empresa $empresa, Agencia $agencia, Cliente $cliente, User $asesor): Credito
{
    $credito = Credito::factory()->diario()->create([
        'registrado_por' => $asesor->id,
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
        'cliente_id' => $cliente->id,
        'estado' => 'activo',
        'fecha_desembolso' => now()->subDays(2)->toDateString(),
        'fecha_vencimiento' => now()->addDays(28)->toDateString(),
        'plazo_dias' => 30,
    ]);

    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 1,
        'fecha_vencimiento' => now()->subDays(1)->toDateString(),
        'monto_capital' => 10, 'monto_interes' => 1, 'monto_total' => 11,
    ]);

    return $credito;
}

it('shows every authorized asesor and their ruta when no filter is given', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)
        ->create(['latitud' => -8.0, 'longitud' => -74.0]);
    creditoEnMoraParaMapa($this->empresa, $this->agencia, $cliente, $this->asesor);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/dashboard/mapa-unificado')->assertSuccessful();

    expect($response->json('data.asesores'))->toHaveCount(1)
        ->and($response->json('data.asesores.0.asesor_id'))->toBe($this->asesor->id)
        ->and($response->json('data.clientes'))->toHaveCount(1)
        ->and($response->json('data.clientes.0.cliente_id'))->toBe($cliente->id)
        ->and($response->json('data.clientes.0.estado_visual'))->toBe('pendiente');
});

it('shows an asesor and their ruta even when they never sent a GPS ping', function () {
    $sinPing = User::factory()->forAgencia($this->agencia)->create();
    $sinPing->assignRole('asesor');

    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($sinPing)->create();
    creditoEnMoraParaMapa($this->empresa, $this->agencia, $cliente, $sinPing);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/dashboard/mapa-unificado')->assertSuccessful();

    $filaAsesor = collect($response->json('data.asesores'))->firstWhere('asesor_id', $sinPing->id);
    $filaCliente = collect($response->json('data.clientes'))->firstWhere('cliente_id', $cliente->id);

    expect($filaAsesor)->not->toBeNull()
        ->and($filaAsesor['latitud'])->toBeNull()
        ->and($filaAsesor['desactualizado'])->toBeTrue()
        ->and($filaCliente)->not->toBeNull()
        ->and($filaCliente['estado_visual'])->toBe('pendiente');
});

it('narrows to one agencia when agencia_id is given', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create();
    creditoEnMoraParaMapa($this->empresa, $this->agencia, $cliente, $this->asesor);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroAsesor = User::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $otroAsesor->id, 'latitud' => -8.1, 'longitud' => -74.1,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $otraAgencia->id,
    ]);

    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $response = $this->getJson('/api/dashboard/mapa-unificado?agencia_id='.$this->agencia->id)->assertSuccessful();

    expect($response->json('data.asesores'))->toHaveCount(1)
        ->and($response->json('data.asesores.0.asesor_id'))->toBe($this->asesor->id);
});

it('narrows to one asesor when asesor_id is given', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create();
    creditoEnMoraParaMapa($this->empresa, $this->agencia, $cliente, $this->asesor);

    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $otroAsesor->id, 'latitud' => -8.1, 'longitud' => -74.1,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/dashboard/mapa-unificado?asesor_id='.$this->asesor->id)->assertSuccessful();

    expect($response->json('data.asesores'))->toHaveCount(1)
        ->and($response->json('data.asesores.0.asesor_id'))->toBe($this->asesor->id);
});

it('never widens access: an asesor outside the actor scope does not appear even when requested by id', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $ajeno = User::factory()->forAgencia($otraAgencia)->create();
    $ajeno->assignRole('asesor');
    UbicacionAsesor::query()->create([
        'user_id' => $ajeno->id, 'latitud' => -8.1, 'longitud' => -74.1,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $otraAgencia->id,
    ]);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/dashboard/mapa-unificado?asesor_id='.$ajeno->id)->assertSuccessful();

    expect($response->json('data.asesores'))->toHaveCount(0);
});

it('marks a cliente verde when a valid cobro exists today, and ignores an anulado cobro', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create();
    $credito = creditoEnMoraParaMapa($this->empresa, $this->agencia, $cliente, $this->asesor);

    Cobro::factory()->create([
        'cliente_id' => $cliente->id, 'credito_id' => $credito->id, 'empresa_id' => $this->empresa->id,
        'estado' => 'anulado', 'monto_pagado' => 50, 'vuelto' => 0,
    ]);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $primero = $this->getJson('/api/dashboard/mapa-unificado')->assertSuccessful();
    expect($primero->json('data.clientes.0.estado_visual'))->toBe('pendiente');

    Cobro::factory()->create([
        'cliente_id' => $cliente->id, 'credito_id' => $credito->id, 'empresa_id' => $this->empresa->id,
        'estado' => 'registrado', 'monto_pagado' => 50, 'vuelto' => 0,
    ]);

    $segundo = $this->getJson('/api/dashboard/mapa-unificado')->assertSuccessful();
    expect($segundo->json('data.clientes.0.estado_visual'))->toBe('verde')
        ->and($segundo->json('data.clientes.0.cobrado_hoy'))->toBeTrue();
});

it('marks a cliente amarillo when the visit is confirmed today', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create();
    creditoEnMoraParaMapa($this->empresa, $this->agencia, $cliente, $this->asesor);

    VisitaCliente::query()->create([
        'cliente_id' => $cliente->id, 'asesor_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id, 'fecha' => now()->toDateString(),
        'inicio_en' => now()->subMinutes(5), 'ultima_lectura_en' => now()->subMinutes(2), 'confirmada_en' => now()->subMinutes(2),
    ]);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/dashboard/mapa-unificado')->assertSuccessful();

    expect($response->json('data.clientes.0.estado_visual'))->toBe('amarillo')
        ->and($response->json('data.clientes.0.permanencia.confirmada_en'))->not->toBeNull();
});

it('prefers verde over amarillo when both a confirmed visit and a cobro exist', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create();
    $credito = creditoEnMoraParaMapa($this->empresa, $this->agencia, $cliente, $this->asesor);

    VisitaCliente::query()->create([
        'cliente_id' => $cliente->id, 'asesor_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id,
        'agencia_id' => $this->agencia->id, 'fecha' => now()->toDateString(),
        'inicio_en' => now()->subMinutes(5), 'ultima_lectura_en' => now()->subMinutes(2), 'confirmada_en' => now()->subMinutes(2),
    ]);
    Cobro::factory()->create([
        'cliente_id' => $cliente->id, 'credito_id' => $credito->id, 'empresa_id' => $this->empresa->id,
        'estado' => 'registrado', 'monto_pagado' => 50, 'vuelto' => 0,
    ]);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');
    Sanctum::actingAs($admin, ['*']);

    $response = $this->getJson('/api/dashboard/mapa-unificado')->assertSuccessful();

    expect($response->json('data.clientes.0.estado_visual'))->toBe('verde');
});

it('denies a user without ubicaciones_asesores.ver even if they can view créditos', function () {
    $secretaria = User::factory()->forAgencia($this->agencia)->create();
    $secretaria->assignRole('secretaria');
    Sanctum::actingAs($secretaria, ['*']);

    $this->getJson('/api/dashboard/mapa-unificado')->assertForbidden();
});
