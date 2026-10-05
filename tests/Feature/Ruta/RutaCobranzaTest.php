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

    $creditoA1 = creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 5);
    $creditoA2 = creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 3);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteB, $this->asesor, 10);

    Sanctum::actingAs($this->asesor, ['*']);
    $response = $this->getJson('/api/rutas-cobranza')->assertSuccessful();

    expect($response->json('data'))->toHaveCount(2);

    $filaA = collect($response->json('data'))->firstWhere('cliente_id', $clienteA->id);
    expect($filaA['creditos_vencidos'])->toBe(2)
        ->and($filaA['numero_documento'])->toBe($clienteA->numero_documento)
        ->and(collect($filaA['creditos'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$creditoA1->id, $creditoA2->id])->sort()->values()->all())
        ->and($filaA['creditos'][0])->toHaveKeys(['id', 'codigo', 'tipo_credito']);

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

/** Supervisor de la agencia con $asesores colgados de su (ids en el orden dado). */
function supervisorDeRutaParaTest(Agencia $agencia, User ...$asesores): User
{
    $supervisor = User::factory()->forAgencia($agencia)->create();
    $supervisor->assignRole('supervisor');

    foreach ($asesores as $asesor) {
        $asesor->update(['supervisor_id' => $supervisor->id]);
    }

    return $supervisor;
}

it('clones a cliente of an asesor into the supervisor ruta without removing it from the asesor', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente, $this->asesor, 5);

    Sanctum::actingAs($supervisor, ['*']);

    $response = $this->postJson("/api/rutas-cobranza/clientes/{$cliente->id}/clonar")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Cliente agregado a tu ruta')
        ->assertJsonPath('data.0.cliente_id', $cliente->id)
        ->assertJsonPath('data.0.clonado', true)
        ->assertJsonPath('data.0.asesor_id', $this->asesor->id)
        ->assertJsonPath('data.0.dias_atraso_max', 4);

    expect($response->json('data.0.orden'))->toBe(1);

    // El cliente sigue en la ruta de su asesor, y con su propio orden.
    Sanctum::actingAs($this->asesor, ['*']);
    expect($this->getJson('/api/rutas-cobranza')->json('data.0.cliente_id'))->toBe($cliente->id);
});

it('builds a single supervisor ruta with paradas from varios asesores', function () {
    $asesor2 = User::factory()->forAgencia($this->agencia)->create();
    $asesor2->assignRole('asesor');
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor, $asesor2);

    $cliente1 = Cliente::factory()->asignadoA($this->asesor)->create();
    $cliente2 = Cliente::factory()->asignadoA($asesor2)->create();
    $cliente3 = Cliente::factory()->asignadoA($asesor2)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente1, $this->asesor, 5);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente2, $asesor2, 3);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente3, $asesor2, 1);

    Sanctum::actingAs($supervisor, ['*']);

    // El supervisor no ve la ruta de nadie por defecto: solo lo que él clona.
    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toBe([]);

    // Primero ve la ruta del asesor 1 y clona un cliente de él.
    $this->getJson("/api/rutas-cobranza?asesor_id={$this->asesor->id}")->assertSuccessful();
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente1->id}/clonar")->assertSuccessful();

    // Y después la del asesor 2, de donde toma otro cliente (y solo ese).
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente2->id}/clonar")->assertSuccessful();

    $ruta = $this->getJson('/api/rutas-cobranza')->assertSuccessful();
    expect(collect($ruta->json('data'))->pluck('cliente_id')->all())->toBe([$cliente1->id, $cliente2->id]);

    // Los clientes del asesor 2 que no clonó siguen en la ruta del asesor.
    Sanctum::actingAs($asesor2, ['*']);
    expect(collect($this->getJson('/api/rutas-cobranza')->json('data'))->pluck('cliente_id')->all())
        ->toBe([$cliente2->id, $cliente3->id]);
});

it('marks the paradas already cloned in the supervisor ruta when viewing an asesor ruta', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $clonado = Cliente::factory()->asignadoA($this->asesor)->create();
    $pendiente = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clonado, $this->asesor, 5);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $pendiente, $this->asesor, 3);

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$clonado->id}/clonar")->assertSuccessful();

    $ruta = collect($this->getJson("/api/rutas-cobranza?asesor_id={$this->asesor->id}")->json('data'));

    expect($ruta->firstWhere('cliente_id', $clonado->id)['en_mi_ruta'])->toBeTrue()
        ->and($ruta->firstWhere('cliente_id', $pendiente->id)['en_mi_ruta'])->toBeFalse()
        ->and($ruta->firstWhere('cliente_id', $pendiente->id)['clonado'])->toBeFalse();
});

it('keeps the position of a parada when it is cloned twice', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $cliente1 = Cliente::factory()->asignadoA($this->asesor)->create();
    $cliente2 = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente1, $this->asesor, 5);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente2, $this->asesor, 3);

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente1->id}/clonar")->assertSuccessful();
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente2->id}/clonar")->assertSuccessful();

    // Repetir el clonado no duplica la parada ni la mueve de posición.
    $ruta = $this->postJson("/api/rutas-cobranza/clientes/{$cliente1->id}/clonar")
        ->assertSuccessful()
        ->assertJsonCount(2, 'data');

    expect(collect($ruta->json('data'))->pluck('cliente_id')->all())->toBe([$cliente1->id, $cliente2->id]);
});

it('clones a cliente into the supervisor ruta of the requested tipo de crédito only', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente, $this->asesor, 5);

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente->id}/clonar", ['tipo_credito' => 'diario'])->assertSuccessful();

    expect($this->getJson('/api/rutas-cobranza?tipo_credito=diario')->json('data.0.cliente_id'))->toBe($cliente->id)
        ->and($this->getJson('/api/rutas-cobranza?tipo_credito=prendario')->json('data'))->toBe([])
        ->and($this->getJson('/api/rutas-cobranza')->json('data'))->toBe([]);

    $this->postJson("/api/rutas-cobranza/clientes/{$cliente->id}/clonar", ['tipo_credito' => 'hipoteca'])
        ->assertUnprocessable();
});

it('rejects cloning a cliente whose advisor is not a supervisor asesor', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');
    $clienteAjeno = Cliente::factory()->asignadoA($otroAsesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteAjeno, $otroAsesor, 5);

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$clienteAjeno->id}/clonar")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Ese cliente no pertenece a ninguno de tus asesores.');

    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toBe([]);
});

it('rejects cloning a cliente without overdue cuotas', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente->id}/clonar")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'El cliente no tiene cuotas vencidas pendientes en esa ruta.');
});

it('denies cloning to an asesor', function () {
    $clienteAjeno = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteAjeno, $this->asesor, 5);

    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Sanctum::actingAs($otroAsesor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$clienteAjeno->id}/clonar")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Solo un supervisor puede clonar clientes en su ruta.');

    $this->deleteJson("/api/rutas-cobranza/clientes/{$clienteAjeno->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Solo un supervisor puede quitar clientes en su ruta.');
});

it('lets a supervisor reorder their own ruta with cloned paradas', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $cliente1 = Cliente::factory()->asignadoA($this->asesor)->create();
    $cliente2 = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente1, $this->asesor, 5);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente2, $this->asesor, 3);

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente1->id}/clonar")->assertSuccessful();
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente2->id}/clonar")->assertSuccessful();

    $this->postJson('/api/rutas-cobranza/reordenar', ['cliente_ids' => [$cliente2->id, $cliente1->id]])
        ->assertSuccessful()
        ->assertJsonPath('data.0.cliente_id', $cliente2->id)
        ->assertJsonPath('data.1.cliente_id', $cliente1->id);

    // La ruta del asesor no se reordena desde la del supervisor.
    Sanctum::actingAs($this->asesor, ['*']);
    expect($this->getJson('/api/rutas-cobranza')->json('data.0.cliente_id'))->toBe($cliente1->id);
});

it('removes a cloned cliente from the supervisor ruta only', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $cliente1 = Cliente::factory()->asignadoA($this->asesor)->create();
    $cliente2 = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente1, $this->asesor, 5);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente2, $this->asesor, 3);

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente1->id}/clonar")->assertSuccessful();
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente2->id}/clonar")->assertSuccessful();

    $ruta = $this->deleteJson("/api/rutas-cobranza/clientes/{$cliente1->id}")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Cliente quitado de tu ruta');

    expect(collect($ruta->json('data'))->pluck('cliente_id')->all())->toBe([$cliente2->id]);

    // Se puede volver a clonar y el asesor nunca lo perdió.
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente1->id}/clonar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    expect(collect($this->getJson('/api/rutas-cobranza')->json('data'))->pluck('cliente_id')->all())
        ->toBe([$cliente1->id, $cliente2->id]);
});

it('rejects removing a cliente of the supervisor own cartera', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia);
    $clientePropio = Cliente::factory()->asignadoA($supervisor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clientePropio, $supervisor, 5);

    Sanctum::actingAs($supervisor, ['*']);
    $this->deleteJson("/api/rutas-cobranza/clientes/{$clientePropio->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Los clientes de tu cartera ya están en tu ruta, no se pueden quitar.');
});

it('drops a cloned cliente from the supervisor ruta once its overdue cuotas were paid', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();
    $credito = creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente, $this->asesor, 5);

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente->id}/clonar")->assertSuccessful();
    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toHaveCount(1);

    CuotaCredito::where('credito_id', $credito->id)
        ->whereDate('fecha_vencimiento', '<=', now()->toDateString())
        ->update(['pagada_at' => now()]);

    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toBe([]);
});

it('drops a cloned parada when the cliente leaves the cartera of the asesor', function () {
    $supervisor = supervisorDeRutaParaTest($this->agencia, $this->asesor);
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente, $this->asesor, 5);

    Sanctum::actingAs($supervisor, ['*']);
    $this->postJson("/api/rutas-cobranza/clientes/{$cliente->id}/clonar")->assertSuccessful();
    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toHaveCount(1);

    // El cliente pasa a un asesor de otro supervisor.
    $otroSupervisor = supervisorDeRutaParaTest($this->agencia, $otroAsesor);
    $cliente->update(['asesor_id' => $otroAsesor->id]);

    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toBe([]);

    // Tampoco sale de la ruta del asesor al que ya no pertenece.
    Sanctum::actingAs($this->asesor, ['*']);
    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toBe([]);

    // Y el nuevo supervisor puede clonarlo en la suya.
    Sanctum::actingAs($otroSupervisor, ['*']);
    expect($this->postJson("/api/rutas-cobranza/clientes/{$cliente->id}/clonar")->assertSuccessful()->json('data'))->toHaveCount(1);
});

/** Crédito prendario activo con una única cuota ya vencida hace $diasAtraso días. */
function prendarioEnMoraParaRuta(Empresa $empresa, Agencia $agencia, Cliente $cliente, User $asesor, int $diasAtraso): Credito
{
    $credito = Credito::factory()->create([
        'registrado_por' => $asesor->id,
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
        'cliente_id' => $cliente->id,
        'tipo_credito' => 'prendario',
        'estado' => 'activo',
    ]);

    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 1,
        'fecha_vencimiento' => now()->subDays($diasAtraso)->toDateString(),
        'monto_capital' => 100, 'monto_interes' => 10, 'monto_total' => 110,
    ]);

    return $credito;
}

it('filters the ruta by tipo de crédito', function () {
    $clienteDiario = Cliente::factory()->asignadoA($this->asesor)->create();
    $clientePrendario = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteDiario, $this->asesor, 4);
    prendarioEnMoraParaRuta($this->empresa, $this->agencia, $clientePrendario, $this->asesor, 6);

    Sanctum::actingAs($this->asesor, ['*']);

    expect(collect($this->getJson('/api/rutas-cobranza')->json('data'))->pluck('cliente_id')->sort()->values()->all())
        ->toBe(collect([$clienteDiario->id, $clientePrendario->id])->sort()->values()->all());

    expect(collect($this->getJson('/api/rutas-cobranza?tipo_credito=diario')->json('data'))->pluck('cliente_id')->all())
        ->toBe([$clienteDiario->id]);

    expect(collect($this->getJson('/api/rutas-cobranza?tipo_credito=prendario')->json('data'))->pluck('cliente_id')->all())
        ->toBe([$clientePrendario->id]);

    expect($this->getJson('/api/rutas-cobranza?tipo_credito=hipotecario')->json('data'))->toBe([]);
});

it('counts only the créditos of the requested tipo for a cliente with several tipos en mora', function () {
    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();
    $diario = creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente, $this->asesor, 3);
    $prendario = prendarioEnMoraParaRuta($this->empresa, $this->agencia, $cliente, $this->asesor, 9);

    Sanctum::actingAs($this->asesor, ['*']);

    $general = $this->getJson('/api/rutas-cobranza')->json('data.0');
    expect($general['creditos_vencidos'])->toBe(2)
        ->and($general['dias_atraso_max'])->toBe(9);

    $soloDiario = $this->getJson('/api/rutas-cobranza?tipo_credito=diario')->json('data.0');
    expect($soloDiario['creditos_vencidos'])->toBe(1)
        ->and($soloDiario['credito_codigos'])->toBe([$diario->codigo])
        ->and($soloDiario['dias_atraso_max'])->toBe(2);

    $soloPrendario = $this->getJson('/api/rutas-cobranza?tipo_credito=prendario')->json('data.0');
    expect($soloPrendario['credito_codigos'])->toBe([$prendario->codigo]);
});

it('keeps an independent order for each tipo de crédito route', function () {
    $clienteA = Cliente::factory()->asignadoA($this->asesor)->create();
    $clienteB = Cliente::factory()->asignadoA($this->asesor)->create();
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 3);
    creditoEnMoraParaRuta($this->empresa, $this->agencia, $clienteB, $this->asesor, 10);
    prendarioEnMoraParaRuta($this->empresa, $this->agencia, $clienteA, $this->asesor, 3);
    prendarioEnMoraParaRuta($this->empresa, $this->agencia, $clienteB, $this->asesor, 10);

    Sanctum::actingAs($this->asesor, ['*']);

    $orden = fn (?string $tipo) => collect($this->getJson('/api/rutas-cobranza'.($tipo ? "?tipo_credito={$tipo}" : ''))->json('data'))->pluck('cliente_id')->all();

    // Por defecto en cada ruta el más atrasado (B) va primero.
    expect($orden('diario'))->toBe([$clienteB->id, $clienteA->id])
        ->and($orden('prendario'))->toBe([$clienteB->id, $clienteA->id]);

    $this->postJson('/api/rutas-cobranza/reordenar', ['tipo_credito' => 'diario', 'cliente_ids' => [$clienteA->id, $clienteB->id]])
        ->assertSuccessful()
        ->assertJsonPath('data.0.cliente_id', $clienteA->id);

    expect($orden('diario'))->toBe([$clienteA->id, $clienteB->id])
        ->and($orden('prendario'))->toBe([$clienteB->id, $clienteA->id])
        ->and($orden(null))->toBe([$clienteB->id, $clienteA->id]);
});

it('rejects an unknown tipo de crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->getJson('/api/rutas-cobranza?tipo_credito=hipoteca')->assertUnprocessable();
    $this->postJson('/api/rutas-cobranza/reordenar', ['tipo_credito' => 'otro', 'cliente_ids' => [1]])->assertUnprocessable();
});

it('no longer lists a cliente whose overdue cuotas were already paid', function () {
    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();
    $credito = creditoEnMoraParaRuta($this->empresa, $this->agencia, $cliente, $this->asesor, 3);

    Sanctum::actingAs($this->asesor, ['*']);
    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toHaveCount(1);

    CuotaCredito::where('credito_id', $credito->id)
        ->whereDate('fecha_vencimiento', '<=', now()->toDateString())
        ->update(['pagada_at' => now()]);

    expect($this->getJson('/api/rutas-cobranza')->json('data'))->toBe([]);
});
