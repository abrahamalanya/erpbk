<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
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

it('registers a hipotecario crédito with an aval, persisted and returned in the detail', function () {
    $aval = Cliente::factory()->forAgencia($this->agencia)->create(['nombre' => 'Marta', 'apellido' => 'Garante']);

    Sanctum::actingAs($this->asesor, ['*']);

    $creditoId = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'aval_id' => $aval->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->assertJsonPath('data.aval.id', $aval->id)->json('data.id');

    expect(Credito::find($creditoId)->aval_id)->toBe($aval->id);

    $this->getJson("/api/creditos-prendarios/{$creditoId}")
        ->assertOk()
        ->assertJsonPath('data.aval.nombre', 'Marta')
        ->assertJsonCount(1, 'data.inmuebles')
        ->assertJsonPath('data.inmuebles.0.id', $this->inmueble->id)
        ->assertJsonPath('data.inmuebles.0.partida_registral', $this->inmueble->partida_registral);
});

it('includes the inmueble garantía in the crédito listing', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated();

    $this->getJson('/api/creditos-prendarios')
        ->assertOk()
        ->assertJsonPath('data.data.0.inmuebles.0.id', $this->inmueble->id);
});

it('generates the hipotecario-only documentos when registering (ficha + cobranza + expediente)', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $creditoId = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $tipos = Credito::find($creditoId)->documentos()->pluck('tipo')->all();
    expect($tipos)->toContain('ficha_socioeconomica')
        ->and($tipos)->toContain('notificacion_pago')
        ->and($tipos)->toContain('aviso_prejudicial')
        ->and($tipos)->toContain('expediente')
        // El sticker se pega sobre un bien/vehículo físico en tienda; un
        // hipotecario no tiene artículo que etiquetar.
        ->and($tipos)->not->toContain('sticker');
});

it('persists a second aval and returns it in the detail', function () {
    $aval1 = Cliente::factory()->forAgencia($this->agencia)->create(['nombre' => 'Ana', 'apellido' => 'Uno']);
    $aval2 = Cliente::factory()->forAgencia($this->agencia)->create(['nombre' => 'Beto', 'apellido' => 'Dos']);

    Sanctum::actingAs($this->asesor, ['*']);

    $creditoId = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'aval_id' => $aval1->id,
        'aval_2_id' => $aval2->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    expect(Credito::find($creditoId)->aval_2_id)->toBe($aval2->id);

    $this->getJson("/api/creditos-prendarios/{$creditoId}")
        ->assertOk()
        ->assertJsonPath('data.aval.id', $aval1->id)
        ->assertJsonPath('data.aval2.id', $aval2->id);
});

it('rejects a second aval equal to the first', function () {
    $aval = Cliente::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($this->asesor, ['*']);

    $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'aval_id' => $aval->id,
        'aval_2_id' => $aval->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertStatus(422)->assertJsonValidationErrors('aval_2_id');
});

it('uploads, lists and deletes expediente images and renders the expediente PDF', function () {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);

    $creditoId = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    // El expediente ya se generó (sin fotos) — el PDF se sirve igual.
    $docId = Credito::find($creditoId)->documentos()->where('tipo', 'expediente')->value('id');
    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$docId}/ver")
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    // Subir 2 imágenes a una sección del deudor.
    $this->postJson("/api/creditos-prendarios/{$creditoId}/expediente", [
        'rol' => 'deudor',
        'seccion' => 'terreno',
        'archivos' => [
            UploadedFile::fake()->image('t1.jpg', 400, 300),
            UploadedFile::fake()->image('t2.jpg', 400, 300),
        ],
    ])->assertCreated();

    $lista = $this->getJson("/api/creditos-prendarios/{$creditoId}/expediente")->assertOk()->json('data');
    expect($lista)->toHaveCount(2)
        ->and($lista[0]['rol'])->toBe('deudor')
        ->and($lista[0]['seccion'])->toBe('terreno');

    // Rechaza un PDF (solo imágenes).
    $this->postJson("/api/creditos-prendarios/{$creditoId}/expediente", [
        'rol' => 'deudor', 'seccion' => 'copia_literal',
        'archivos' => [UploadedFile::fake()->create('x.pdf', 100, 'application/pdf')],
    ])->assertStatus(422);

    // Render con fotos.
    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$docId}/ver")
        ->assertOk()->assertHeader('content-type', 'application/pdf');

    // Borrar una.
    $this->deleteJson("/api/creditos-prendarios/{$creditoId}/expediente/{$lista[0]['id']}")->assertOk();
    expect($this->getJson("/api/creditos-prendarios/{$creditoId}/expediente")->json('data'))->toHaveCount(1);
});

it('denies expediente uploads to an asesor who cannot manage the crédito', function () {
    $otroAsesor = User::factory()->forAgencia($this->agencia)->create();
    $otroAsesor->assignRole('asesor');

    Sanctum::actingAs($this->asesor, ['*']);
    $creditoId = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($otroAsesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/expediente", [
        'rol' => 'deudor', 'seccion' => 'terreno',
        'archivos' => [UploadedFile::fake()->image('t.jpg')],
    ])->assertForbidden();
});

it('streams the notificacion_pago and aviso_prejudicial PDFs with the overdue cuotas', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $this->empresa->update(['razon_social' => 'CREDIMAS ORIENTE E.I.R.L.', 'ruc' => '20602137903', 'apoderado_legal' => 'Norma Quispe Quicaña', 'celular_cobranzas' => '965263936']);

    $creditoId = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 15000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $credito = Credito::find($creditoId);

    // Sin cuotas vencidas: los PDF se sirven igual (tabla vacía). El admin
    // puede ver documentos aunque el crédito esté pendiente.
    Sanctum::actingAs($this->adminAgencia, ['*']);
    foreach (['notificacion_pago', 'aviso_prejudicial'] as $tipo) {
        $docId = $credito->documentos()->where('tipo', $tipo)->value('id');
        $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$docId}/ver")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    // Con 3 cuotas vencidas (y una futura que NO cuenta): deuda = 3 × 3,200 = 9,600.
    CuotaCredito::factory()->paraCredito($credito)->createMany([
        ['numero_cuota' => 1, 'fecha_vencimiento' => now()->subMonths(3)->toDateString(), 'monto_capital' => 3000, 'monto_interes' => 200, 'monto_total' => 3200],
        ['numero_cuota' => 2, 'fecha_vencimiento' => now()->subMonths(2)->toDateString(), 'monto_capital' => 3000, 'monto_interes' => 200, 'monto_total' => 3200],
        ['numero_cuota' => 3, 'fecha_vencimiento' => now()->subMonth()->toDateString(), 'monto_capital' => 3000, 'monto_interes' => 200, 'monto_total' => 3200],
        ['numero_cuota' => 4, 'fecha_vencimiento' => now()->addMonth()->toDateString(), 'monto_capital' => 3000, 'monto_interes' => 200, 'monto_total' => 3200],
    ]);

    $credito->load(['cliente', 'agencia', 'empresa', 'cuotas']);
    $doc = $credito->documentos()->where('tipo', 'aviso_prejudicial')->first();
    $html = view('modules.credito-hipotecario.documentos.aviso_prejudicial', ['credito' => $credito, 'documento' => $doc])->render();

    expect($html)->toContain('9,600.00')
        ->and($html)->toContain('NUEVE MIL SEISCIENTOS CON 00/100 SOLES')
        ->and($html)->toContain('3 CUOTA(S)')
        ->and($html)->toContain('CREDIMAS ORIENTE E.I.R.L.')
        ->and($html)->toContain('NORMA QUISPE QUICAÑA')
        ->and(substr_count($html, '<tr>'))->toBeGreaterThanOrEqual(4); // encabezado + 3 vencidas
});

it('streams the ficha_socioeconomica PDF, with and without a ficha loaded on the cliente', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    // Sin ficha en el cliente: el PDF igual se genera (campos en blanco).
    $creditoId = $this->postJson('/api/creditos-hipotecarios', [
        'inmueble_ids' => [$this->inmueble->id],
        'supervisado_por' => $this->adminAgencia->id,
        'monto_prestamo' => 90000,
        'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');

    $docId = Credito::find($creditoId)->documentos()->where('tipo', 'ficha_socioeconomica')->value('id');

    // Un asesor no puede ver documentos mientras el crédito está pendiente; el admin sí.
    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$docId}/ver")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // Con ficha: se re-renderiza fresco con los datos.
    $this->putJson("/api/clientes/{$this->cliente->id}/ficha-socioeconomica", [
        'profesion' => 'contadora',
        'ing_conyuge' => 1200,
        'familiares' => [['nombres' => 'Hijo Uno', 'edad' => 10, 'parentesco' => 'hijo']],
    ])->assertSuccessful();

    $res = $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$docId}/ver")->assertOk();
    expect($res->headers->get('content-type'))->toContain('application/pdf');
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
