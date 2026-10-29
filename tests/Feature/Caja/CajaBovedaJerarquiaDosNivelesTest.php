<?php

use App\Modules\Caja\Models\Boveda;
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

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $this->administradorGeneral->assignRole('administrador_general');
});

/**
 * Deja la bóveda de agencia cerrada (ciclo de HOY) con la bóveda principal
 * todavía abierta, y luego la "envejece" a un ciclo de ayer — para
 * reproducir una agencia fresca financiada por una principal atascada, sin
 * que CajaAperturaFechaAnteriorTest's chequeo de un solo nivel (financiadora
 * directa) lo detecte.
 */
function dejarPrincipalAtascadaDeAyer($test): Boveda
{
    Sanctum::actingAs($test->asesor, ['*']);
    $test->postJson('/api/caja/aperturar')->assertCreated();
    $test->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $test->agencia->id)->firstOrFail();
    $bovedaPrincipal = Boveda::query()->where('empresa_id', $test->empresa->id)->where('tipo', 'principal')->firstOrFail();

    Sanctum::actingAs($test->administradorAgencia, ['*']);
    $test->postJson("/api/bovedas/{$bovedaAgencia->id}/cerrar", ['monto_contado' => 0])->assertSuccessful();

    $bovedaPrincipal->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);

    return $bovedaPrincipal;
}

it('blocks aperturar-ing a caja when the agencia boveda is fresh but the PRINCIPAL above it is still open from a previous day', function () {
    $bovedaPrincipal = dejarPrincipalAtascadaDeAyer($this);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->postJson('/api/caja/aperturar')->assertUnprocessable();

    expect($response->json('message'))->toContain('bóveda principal')
        ->toContain('ciclo abierto de un día anterior');

    // La bóveda de agencia (fresca) no debe haber quedado creada a medias.
    expect(Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail()->cicloAbierto()->exists())->toBeFalse();
});

it('allows aperturar-ing again once the stale PRINCIPAL ciclo is closed', function () {
    $bovedaPrincipal = dejarPrincipalAtascadaDeAyer($this);

    Sanctum::actingAs($this->administradorGeneral, ['*']);
    $this->postJson("/api/bovedas/{$bovedaPrincipal->id}/cerrar", ['monto_contado' => 0])->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    expect($bovedaAgencia->cicloAbierto->fecha->toDateString())->toBe(now()->toDateString())
        ->and($bovedaPrincipal->fresh()->cicloAbierto->fecha->toDateString())->toBe(now()->toDateString());
});

it('still blocks the direct one-level stale case (regression against the existing CajaAperturaFechaAnteriorTest behavior)', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $this->postJson('/api/caja/cerrar', ['monto_contado' => 0])->assertSuccessful();

    $bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $bovedaAgencia->cicloAbierto()->update(['fecha' => now()->subDay()->toDateString()]);

    $this->postJson('/api/caja/aperturar')
        ->assertStatus(409)
        ->assertJsonPath('reason', 'caja_abierta')
        ->assertJsonPath('message', 'No puedes aperturar tu caja: la bóveda que te financia sigue con un ciclo abierto de un día anterior.');
});
