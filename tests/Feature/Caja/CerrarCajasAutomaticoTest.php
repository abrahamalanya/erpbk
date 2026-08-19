<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
});

it('closes a stale-dated open caja ciclo automatically, rejecting pending billetajes and handing the balance to the boveda', function () {
    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $bovedaAgenciaId = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->id;
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$bovedaAgenciaId}/inyectar", ['monto' => 500])->assertCreated();

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeAprobadoId = $this->postJson('/api/billetajes', ['monto' => 200])->json('data.id');
    $billetajePendienteId = $this->postJson('/api/billetajes', ['monto' => 50])->json('data.id');

    $administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $administradorAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeAprobadoId}/aprobar")->assertSuccessful();

    $caja = Caja::query()->where('user_id', $asesor->id)->firstOrFail();
    $caja->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);
    $cicloId = $caja->cicloAbierto()->firstOrFail()->id;

    Artisan::call('cajas:cerrar-automatico');

    $ciclo = $caja->fresh()->ciclos()->findOrFail($cicloId);

    expect($ciclo->estado)->toBe('cerrada')
        ->and($ciclo->cierre_automatico)->toBeTrue()
        ->and($ciclo->cierre_forzado)->toBeFalse()
        ->and((string) $ciclo->saldo_calculado_cierre)->toBe('200.00')
        ->and((string) $ciclo->saldo_arqueo_cierre)->toBe('200.00')
        ->and((string) $ciclo->diferencia)->toBe('0.00');

    expect($ciclo->billetajes()->findOrFail($billetajePendienteId)->estado)->toBe('rechazado');

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $movimiento = $bovedaAgencia->cicloAbierto()->firstOrFail()
        ->movimientos()->where('caja_ciclo_id', $cicloId)->where('concepto', 'Entrega por cierre de caja')->first();

    expect($movimiento)->not->toBeNull()
        ->and((string) $movimiento->monto)->toBe('200.00');
});
