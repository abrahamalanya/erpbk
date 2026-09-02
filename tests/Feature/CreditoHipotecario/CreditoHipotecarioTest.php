<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'hipotecario',
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 30, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 200000, 'abierta_at' => now(),
    ]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');

    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->inmueble = Inmueble::factory()->paraCliente($this->cliente)->create(['valorizacion' => 150000]);
});

it('registers a hipotecario crédito with a supervisor', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    expect($response->json('data.estado'))->toBe('pendiente')
        ->and($response->json('data.tipo_credito'))->toBe('hipotecario')
        ->and($response->json('data.supervisado_por.id'))->toBe($this->adminAgencia->id);

    $credito = Credito::find($response->json('data.id'));
    expect($credito->inmuebles()->count())->toBe(1)
        ->and($this->inmueble->fresh()->estado)->toBe('en_garantia');
});

it('rejects a hipotecario crédito when the cliente has no dirección/referencia', function () {
    $this->cliente->update(['direccion' => null, 'referencia' => null]);
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 50000,
        'tipo_cuota' => 'mensual',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Para un crédito hipotecario el cliente debe tener dirección y referencia registradas.');
});

it('requires and validates supervisado_por on a hipotecario crédito', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'monto_prestamo' => 50000,
        'tipo_cuota' => 'mensual',
    ])->assertStatus(422)->assertJsonValidationErrors('supervisado_por');

    $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->asesor->id,
        'monto_prestamo' => 50000,
        'tipo_cuota' => 'mensual',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'El supervisor indicado debe ser un administrador de agencia o supervisor de la empresa.');
});

it('routes a vencido hipotecario crédito through pendiente_conformidad before the tienda', function () {
    Storage::fake('public');

    $credito = Credito::factory()->paraInmueble($this->inmueble)
        ->vencido(diasVencido: 40)
        ->create([
            'registrado_por' => $this->asesor->id,
            'supervisado_por' => $this->adminAgencia->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
        ]);

    app(CreditoService::class)->actualizarEstadosVencidos();
    expect($credito->fresh()->estado)->toBe('pendiente_conformidad');

    Sanctum::actingAs($this->adminAgencia, ['*']);

    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", ['precios' => [$this->inmueble->id => 140000]])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Debes registrar la conformidad del notario/abogado antes de enviar el crédito a la tienda.');

    $this->postJson("/api/creditos-prendarios/{$credito->id}/conformidad", [
        'archivo' => UploadedFile::fake()->create('conformidad.pdf', 40, 'application/pdf'),
    ])->assertSuccessful();

    $this->postJson("/api/creditos-prendarios/{$credito->id}/enviar-tienda", ['precios' => [$this->inmueble->id => 140000]])
        ->assertSuccessful()
        ->assertJsonPath('data.estado', 'en_venta');

    expect($this->inmueble->fresh()->estado)->toBe('disponible_venta')
        ->and($this->inmueble->fresh()->precio_venta)->toBe('140000.00');
});

it('lists a rematado inmueble in the unified tienda feed without leaking registral data', function () {
    $inmueble = Inmueble::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 120000]);
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);

    $data = $this->getJson('/api/tienda/articulos?tipo=inmueble')->assertSuccessful()->json('data.data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['articulo_tipo'])->toBe('inmueble')
        ->and($data[0]['id'])->toBe($inmueble->id);

    $item = $this->getJson("/api/tienda/articulos/inmueble/{$inmueble->id}")->assertSuccessful()->json('data');

    expect($item)->toHaveKey('direccion')
        ->and($item)->not->toHaveKey('partida_registral')
        ->and($item)->not->toHaveKey('propietario')
        ->and($item)->not->toHaveKey('cliente_id');
});
