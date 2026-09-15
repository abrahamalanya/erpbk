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
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05, 'max_cuotas' => 45,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
});

/** Crédito diario a 30 cuotas con $numeroCuotasVencidas ya vencidas (crédito sigue activo). */
function creditoEnMoraParaRuta(Empresa $empresa, Agencia $agencia, Cliente $cliente, User $asesor, int $numeroCuotasVencidas): Credito
{
    $credito = Credito::factory()->diario()->create([
        'registrado_por' => $asesor->id,
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
        'cliente_id' => $cliente->id,
        'estado' => 'activo',
        'fecha_desembolso' => now()->subDays($numeroCuotasVencidas)->toDateString(),
        'fecha_vencimiento' => now()->subDays($numeroCuotasVencidas)->addDays(30)->toDateString(),
        'plazo_dias' => 30,
    ]);

    for ($numero = 1; $numero <= 30; $numero++) {
        CuotaCredito::factory()->paraCredito($credito)->create([
            'numero_cuota' => $numero,
            'fecha_vencimiento' => now()->subDays($numeroCuotasVencidas)->addDays($numero)->toDateString(),
            'monto_capital' => 10, 'monto_interes' => 1, 'monto_total' => 11,
        ]);
    }

    return $credito;
}

it('lists the ruta of clientes en mora, one row per cliente even with 2 créditos vencidos', function () {
    $clienteA = Cliente::factory()->asignadoA($this->asesor)->create();
    $clienteB = Cliente::factory()->asignadoA($this->asesor)->create();

    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 5);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 3);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteB, $this->asesor, 10);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/rutas-cobranza')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(2);

    $filaA = collect($response->json('data'))->firstWhere('cliente_id', $clienteA->id);
    expect($filaA['creditos_vencidos'])->toBe(2);

    // El más atrasado (cliente B, 10 días) entra primero de los nuevos.
    expect($response->json('data.0.cliente_id'))->toBe($clienteB->id);
});

it('persists a custom reorder and keeps it on the next request', function () {
    $clienteA = Cliente::factory()->asignadoA($this->asesor)->create();
    $clienteB = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 3);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteB, $this->asesor, 10);

    Sanctum::actingAs($this->asesor, ['*']);

    // Orden por defecto: B primero (más atrasado). El asesor lo invierte.
    $this->postJson('/api/rutas-cobranza/reordenar', ['cliente_ids' => [$clienteA->id, $clienteB->id]])
        ->assertSuccessful()
        ->assertJsonPath('data.0.cliente_id', $clienteA->id)
        ->assertJsonPath('data.1.cliente_id', $clienteB->id);

    $response = $this->getJson('/api/rutas-cobranza')->assertSuccessful();
    expect($response->json('data.0.cliente_id'))->toBe($clienteA->id)
        ->and($response->json('data.1.cliente_id'))->toBe($clienteB->id);
});

it('appends a newly overdue cliente at the end without disturbing the existing custom order', function () {
    $clienteA = Cliente::factory()->asignadoA($this->asesor)->create();
    $clienteB = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 3);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteB, $this->asesor, 10);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/rutas-cobranza/reordenar', ['cliente_ids' => [$clienteA->id, $clienteB->id]])->assertSuccessful();

    // Un tercer cliente cae en mora recién ahora.
    $clienteC = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteC, $this->asesor, 1);

    $response = $this->getJson('/api/rutas-cobranza')->assertSuccessful();
    expect(collect($response->json('data'))->pluck('cliente_id')->all())
        ->toBe([$clienteA->id, $clienteB->id, $clienteC->id]);
});

it('rejects reordering a cliente that does not belong to the actor cartera', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $clienteAjeno = Cliente::factory()->asignadoA($otroAsesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteAjeno, $otroAsesor, 5);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/rutas-cobranza/reordenar', ['cliente_ids' => [$clienteAjeno->id]])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Uno o más clientes indicados no pertenecen a tu cartera.');
});

it('denies an asesor from viewing another asesor ruta', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson("/api/rutas-cobranza?asesor_id={$otroAsesor->id}")->assertForbidden();
});

it('allows administrador_agencia to view an asesor ruta of their own agencia', function () {
    $clienteA = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 5);

    $admin = User::factory()->forAgencia($this->agencia)->create();
    $admin->assignRole('administrador_agencia');

    Sanctum::actingAs($admin, ['*']);
    $this->getJson("/api/rutas-cobranza?asesor_id={$this->asesor->id}")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data');
});

it('allows a supervisor to view only their own asesores ruta', function () {
    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    $this->asesor->update(['supervisor_id' => $supervisor->id]);

    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Sanctum::actingAs($supervisor, ['*']);
    $this->getJson("/api/rutas-cobranza?asesor_id={$this->asesor->id}")->assertSuccessful();
    $this->getJson("/api/rutas-cobranza?asesor_id={$otroAsesor->id}")->assertForbidden();
});

it('lists only the visible asesores for the ruta selector', function () {
    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    $this->asesor->update(['supervisor_id' => $supervisor->id]);

    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Sanctum::actingAs($supervisor, ['*']);
    $response = $this->getJson('/api/rutas-cobranza/asesores')->assertSuccessful();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$this->asesor->id]);
});
