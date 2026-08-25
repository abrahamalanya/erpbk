<?php

use App\Modules\Cliente\Models\Cliente;
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
    $this->peinadora = User::factory()->forAgencia($this->agencia)->create();
    $this->peinadora->assignRole('peinadora');
});

it('stores the five cliente photos on creation', function () {
    Sanctum::actingAs($this->peinadora, ['*']);

    $response = $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '77777777',
        'foto_cliente' => UploadedFile::fake()->image('cliente.jpg'),
        'foto_dni' => UploadedFile::fake()->image('dni.jpg'),
        'foto_dni_reverso' => UploadedFile::fake()->image('dni-reverso.jpg'),
        'foto_casa' => UploadedFile::fake()->image('casa.jpg'),
        'foto_negocio' => UploadedFile::fake()->image('negocio.jpg'),
    ])->assertCreated();

    $cliente = Cliente::findOrFail($response->json('data.id'));

    expect($cliente->foto_cliente_path)->not->toBeNull()
        ->and($cliente->foto_dni_path)->not->toBeNull()
        ->and($cliente->foto_dni_reverso_path)->not->toBeNull()
        ->and($cliente->foto_casa_path)->not->toBeNull()
        ->and($cliente->foto_negocio_path)->not->toBeNull();

    Storage::disk('public')->assertExists($cliente->foto_cliente_path);

    expect($response->json('data.foto_cliente_url'))->toContain('/storage/clientes/');
});

it('replaces an existing photo and deletes the old file on update', function () {
    $cliente = Cliente::factory()->registradoPor($this->peinadora)->forAgencia($this->agencia)->create([
        'foto_cliente_path' => 'clientes/old-path.jpg',
    ]);
    Storage::disk('public')->put('clientes/old-path.jpg', 'contenido-viejo');

    Sanctum::actingAs($this->peinadora, ['*']);

    $this->post("/api/clientes/{$cliente->id}", [
        '_method' => 'PUT',
        'foto_cliente' => UploadedFile::fake()->image('nueva.jpg'),
    ], ['Accept' => 'application/json'])->assertSuccessful();

    Storage::disk('public')->assertMissing('clientes/old-path.jpg');
    expect($cliente->fresh()->foto_cliente_path)->not->toBe('clientes/old-path.jpg');
});
