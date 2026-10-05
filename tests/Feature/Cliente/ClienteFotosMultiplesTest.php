<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\ClienteFoto;
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
    Storage::fake('public');

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    Sanctum::actingAs($this->admin, ['*']);

    $this->cliente = Cliente::factory()
        ->registradoPor($this->admin)
        ->forAgencia($this->agencia)
        ->create([
            'nombre' => 'Ana',
            'apellido' => 'Torres',
            'tipo_documento' => 'dni',
            'numero_documento' => '44555555',
        ]);
});

/**
 * Payload mínimo válido, con un numero_documento distinto en cada llamada para
 * no chocar con el cliente que crea beforeEach ni entre tests.
 *
 * @return array<string, mixed>
 */
function datosBase(): array
{
    return [
        'nombre' => 'Ana',
        'apellido' => 'Torres',
        'tipo_documento' => 'dni',
        'numero_documento' => fake()->unique()->numerify('########'),
        'agencia_id' => test()->agencia->id,
    ];
}

it('stores several photos per type on creation', function () {
    $response = $this->post('/api/clientes', [
        ...datosBase(),
        'fotos_casa' => [
            UploadedFile::fake()->image('casa1.jpg'),
            UploadedFile::fake()->image('casa2.jpg'),
            UploadedFile::fake()->image('casa3.jpg'),
        ],
        'fotos_negocio' => [
            UploadedFile::fake()->image('negocio1.jpg'),
            UploadedFile::fake()->image('negocio2.jpg'),
        ],
        'fotos_adicionales' => [UploadedFile::fake()->image('extra.jpg')],
    ], ['Accept' => 'application/json'])->assertCreated();

    $fotos = collect($response->json('data.fotos'))
        ->groupBy('tipo')
        ->map(fn ($grupo) => $grupo->pluck('orden')->sort()->values()->all());

    expect($fotos[ClienteFoto::TIPO_CASA])->toBe([0, 1, 2])
        ->and($fotos[ClienteFoto::TIPO_NEGOCIO])->toBe([0, 1])
        ->and($fotos[ClienteFoto::TIPO_ADICIONALES])->toBe([0]);

    foreach ($response->json('data.fotos') as $foto) {
        Storage::disk('public')->assertExists($foto['path']);
        expect($foto['url'])->toContain('/storage/clientes/');
    }
});

it('keeps each type separate and ordered', function () {
    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'fotos_casa' => [
            UploadedFile::fake()->image('casa1.jpg'),
            UploadedFile::fake()->image('casa2.jpg'),
        ],
    ])->assertSuccessful();

    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'fotos_casa' => [UploadedFile::fake()->image('casa3.jpg')],
        'fotos_negocio' => [UploadedFile::fake()->image('negocio1.jpg')],
    ])->assertSuccessful();

    $fotos = $this->cliente->fresh(['fotos'])->fotos;

    expect($fotos->where('tipo', ClienteFoto::TIPO_CASA)->pluck('orden')->all())->toBe([0, 1, 2])
        ->and($fotos->where('tipo', ClienteFoto::TIPO_NEGOCIO)->pluck('orden')->all())->toBe([0]);
});

it('appends without touching the other types when no conservar list is sent', function () {
    $primera = $this->cliente->fotos()->create([
        'tipo' => ClienteFoto::TIPO_CASA,
        'path' => 'clientes/1/casa-inicial.jpg',
        'orden' => 0,
    ]);

    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'fotos_casa' => [UploadedFile::fake()->image('casa2.jpg')],
        'fotos_negocio' => [UploadedFile::fake()->image('negocio.jpg')],
    ])->assertSuccessful();

    $fotos = $this->cliente->fresh(['fotos'])->fotos;

    expect($primera->fresh())->not->toBeNull()
        ->and($fotos->where('tipo', ClienteFoto::TIPO_CASA))->toHaveCount(2)
        ->and($fotos->where('tipo', ClienteFoto::TIPO_NEGOCIO))->toHaveCount(1);
});

it('reconciles the conservador list: drops the omitted and renumbers the kept', function () {
    $casa1 = $this->cliente->fotos()->create([
        'tipo' => ClienteFoto::TIPO_CASA,
        'path' => 'clientes/1/casa1.jpg',
        'orden' => 0,
    ]);
    $casa2 = $this->cliente->fotos()->create([
        'tipo' => ClienteFoto::TIPO_CASA,
        'path' => 'clientes/1/casa2.jpg',
        'orden' => 1,
    ]);
    $casa3 = $this->cliente->fotos()->create([
        'tipo' => ClienteFoto::TIPO_CASA,
        'path' => 'clientes/1/casa3.jpg',
        'orden' => 2,
    ]);
    $negocio = $this->cliente->fotos()->create([
        'tipo' => ClienteFoto::TIPO_NEGOCIO,
        'path' => 'clientes/1/negocio.jpg',
        'orden' => 0,
    ]);

    Storage::disk('public')->put('clientes/1/casa2.jpg', 'x');

    // Se conservan casa3 y casa1, en ese orden: casa2 se elimina y las dos
    // que quedan se renumeran 0 y 1.
    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'fotos_casa_conservar' => [$casa3->id, $casa1->id],
    ])->assertSuccessful();

    $fotos = $this->cliente->fresh(['fotos'])->fotos->where('tipo', ClienteFoto::TIPO_CASA);

    expect($fotos->pluck('id')->all())->toBe([$casa3->id, $casa1->id])
        ->and($fotos->pluck('orden')->all())->toBe([0, 1]);

    expect(ClienteFoto::find($casa2->id))->toBeNull()
        ->and(Storage::disk('public')->exists('clientes/1/casa2.jpg'))->toBeFalse();

    // El tipo negocio no se tocó: su lista de conservar no vino en el request.
    expect($negocio->fresh())->not->toBeNull();
});

it('deletes every foto of a type when an empty conservar list is sent', function () {
    $this->cliente->fotos()->create([
        'tipo' => ClienteFoto::TIPO_CASA,
        'path' => 'clientes/1/casa.jpg',
        'orden' => 0,
    ]);

    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'fotos_casa_conservar' => [],
    ])->assertSuccessful();

    expect($this->cliente->fresh(['fotos'])->fotos)->toHaveCount(0);
});

it('ignores conservar ids that belong to another cliente', function () {
    $otro = Cliente::factory()->registradoPor($this->admin)->forAgencia($this->agencia)->create();
    $fotoAjena = ClienteFoto::factory()->paraCliente($otro)->create([
        'tipo' => ClienteFoto::TIPO_CASA,
    ]);

    $this->cliente->fotos()->create([
        'tipo' => ClienteFoto::TIPO_CASA,
        'path' => 'clientes/1/casa.jpg',
        'orden' => 0,
    ]);

    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'fotos_casa_conservar' => [$fotoAjena->id],
    ])->assertSuccessful();

    // La foto ajena sobrevive y la propia se borró (no estaba en la lista).
    expect($fotoAjena->fresh())->not->toBeNull()
        ->and($this->cliente->fresh(['fotos'])->fotos)->toHaveCount(0);
});

it('deletes a single foto through the endpoint', function () {
    $foto = $this->cliente->fotos()->create([
        'tipo' => ClienteFoto::TIPO_CASA,
        'path' => 'clientes/1/casa.jpg',
        'orden' => 0,
    ]);
    Storage::disk('public')->put('clientes/1/casa.jpg', 'contenido');

    $this->deleteJson("/api/clientes/{$this->cliente->id}/fotos/{$foto->id}")
        ->assertSuccessful();

    expect(ClienteFoto::find($foto->id))->toBeNull()
        ->and(Storage::disk('public')->exists('clientes/1/casa.jpg'))->toBeFalse();
});

it('returns 404 when the foto belongs to another cliente', function () {
    $otro = Cliente::factory()->registradoPor($this->admin)->forAgencia($this->agencia)->create();
    $fotoAjena = ClienteFoto::factory()->paraCliente($otro)->create();

    $this->deleteJson("/api/clientes/{$this->cliente->id}/fotos/{$fotoAjena->id}")
        ->assertNotFound();

    expect($fotoAjena->fresh())->not->toBeNull();
});

it('rejects more than the allowed number of photos per type', function () {
    $this->postJson('/api/clientes', [
        ...datosBase(),
        'fotos_casa' => array_map(
            fn (int $n) => UploadedFile::fake()->image("casa{$n}.jpg"),
            range(1, ClienteFoto::MAX_POR_TIPO + 1)
        ),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('fotos_casa');
});

it('rejects non images inside the multiple photo fields', function () {
    $this->postJson('/api/clientes', [
        ...datosBase(),
        'fotos_adicionales' => [UploadedFile::fake()->create('documento.pdf', 100)],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('fotos_adicionales.0');
});

it('rejects a conservar id that does not exist', function () {
    $this->putJson("/api/clientes/{$this->cliente->id}", [
        'fotos_casa_conservar' => [999999],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('fotos_casa_conservar.0');
});

it('does not expose the fotos of a cliente from another empresa', function () {
    // Un cliente de otra empresa no debe aparecer en el listado, así que sus
    // fotos tampoco pueden quedar expuestas en la respuesta.
    $otraEmpresa = Empresa::factory()->create();
    $otraAgencia = Agencia::factory()->for($otraEmpresa)->create();
    $ajeno = Cliente::factory()->create(['agencia_id' => $otraAgencia->id]);
    $ajeno->fotos()->create([
        'tipo' => ClienteFoto::TIPO_CASA,
        'path' => 'clientes/ajeno/casa.jpg',
    ]);

    $this->getJson('/api/clientes')->assertSuccessful();

    $ids = collect($this->getJson('/api/clientes')->json('data.data'))
        ->flatMap(fn (array $fila) => $fila['fotos'])
        ->pluck('path')
        ->all();

    expect($ids)->not->toContain('clientes/ajeno/casa.jpg');
});
