<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
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
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05, 'max_cuotas' => 45,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
});

/**
 * Crédito diario a 30 cuotas, desembolsado hace $diasDesdeDesembolso días:
 * las primeras cuotas ya vencieron pero el crédito completo (plazo 30 días)
 * sigue 'activo' — exactamente el hueco que este reporte cubre.
 */
function creditoConCuotasVencidas(Empresa $empresa, Agencia $agencia, Cliente $cliente, User $asesor, int $diasDesdeDesembolso): Credito
{
    $credito = Credito::factory()->diario()
        ->create([
            'registrado_por' => $asesor->id,
            'empresa_id' => $empresa->id,
            'agencia_id' => $agencia->id,
            'cliente_id' => $cliente->id,
            'estado' => 'activo',
            'fecha_desembolso' => now()->subDays($diasDesdeDesembolso)->toDateString(),
            'fecha_vencimiento' => now()->subDays($diasDesdeDesembolso)->addDays(30)->toDateString(),
            'plazo_dias' => 30,
        ]);

    for ($numero = 1; $numero <= 30; $numero++) {
        CuotaCredito::factory()->paraCredito($credito)->create([
            'numero_cuota' => $numero,
            'fecha_vencimiento' => now()->subDays($diasDesdeDesembolso)->addDays($numero)->toDateString(),
            'monto_capital' => 10,
            'monto_interes' => 1,
            'monto_total' => 11,
        ]);
    }

    return $credito;
}

it('lists a cliente whose crédito has an overdue cuota, even though the crédito itself is still activo', function () {
    $credito = creditoConCuotasVencidas($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasDesdeDesembolso: 5);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/reportes/cobranza-diaria')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1);

    $fila = $response->json('data.0');
    expect($fila['credito_id'])->toBe($credito->id)
        ->and($fila['credito_codigo'])->toBe($credito->codigo)
        ->and($fila['estado'])->toBe('activo')
        ->and($fila['cliente']['id'])->toBe($this->cliente->id)
        ->and($fila['cuotas_vencidas'])->toBe(5)
        ->and($fila['dias_atraso'])->toBe(4);
});

it('shows a client with 2 créditos overdue as 2 rows, distinguished by credito_codigo', function () {
    $credito1 = creditoConCuotasVencidas($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasDesdeDesembolso: 3);
    $credito2 = creditoConCuotasVencidas($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasDesdeDesembolso: 10);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/reportes/cobranza-diaria')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(2);

    $codigos = collect($response->json('data'))->pluck('credito_codigo')->all();
    expect($codigos)->toContain($credito1->codigo)
        ->and($codigos)->toContain($credito2->codigo)
        ->and($credito1->codigo)->not->toBe($credito2->codigo);
});

it('excludes a crédito with no cuota due yet', function () {
    Credito::factory()->diario()
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
            'estado' => 'activo',
            'fecha_desembolso' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ])
        ->cuotas()
        ->create([
            'empresa_id' => $this->empresa->id,
            'numero_cuota' => 1,
            'fecha_vencimiento' => now()->addDay()->toDateString(),
            'monto_capital' => 10, 'monto_interes' => 1, 'monto_total' => 11,
        ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/reportes/cobranza-diaria')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('excludes a crédito that already liquidó even if it has cuotas with a past fecha_vencimiento', function () {
    $credito = creditoConCuotasVencidas($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasDesdeDesembolso: 5);
    $credito->update(['estado' => 'liquidado']);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/reportes/cobranza-diaria')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('includes a cuota due exactly today (vence_hoy)', function () {
    $credito = Credito::factory()->diario()
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
            'estado' => 'activo',
            'fecha_desembolso' => now()->subDay()->toDateString(),
            'fecha_vencimiento' => now()->addDays(29)->toDateString(),
        ]);
    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 1,
        'fecha_vencimiento' => now()->toDateString(),
        'monto_capital' => 10, 'monto_interes' => 1, 'monto_total' => 11,
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/reportes/cobranza-diaria')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.vence_hoy'))->toBeTrue()
        ->and($response->json('data.0.dias_atraso'))->toBe(0);
});

it('hides créditos from another agencia to an asesor', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroCliente = Cliente::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor = User::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    creditoConCuotasVencidas($this->empresa, $otraAgencia, $otroCliente, $otroAsesor, diasDesdeDesembolso: 5);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/reportes/cobranza-diaria')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('denies access to a user without creditos_prendarios.ver', function () {
    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($sinPermiso, ['*']);
    $this->getJson('/api/reportes/cobranza-diaria')->assertForbidden();
});
