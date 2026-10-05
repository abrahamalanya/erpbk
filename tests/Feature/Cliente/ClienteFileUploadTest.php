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

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    Sanctum::actingAs($this->admin, ['*']);
});

it('stores the single-file photos on creation', function () {
    $response = $this->post('/api/clientes', [
        'nombre' => 'Juan',
        'apellido' => 'Perez',
        'tipo_documento' => 'dni',
        'numero_documento' => '77999991',
        'agencia_id' => $this->agencia->id,
        'foto_cliente' => UploadedFile::fake()->image('cliente.jpg'),
        'foto_dni' => UploadedFile::fake()->image('dni.jpg'),
        'foto_dni_reverso' => UploadedFile::fake()->image('dni-reverso.jpg'),
        'foto_suministro' => UploadedFile::fake()->image('suministro.jpg'),
        'foto_recibo_luz' => UploadedFile::fake()->image('recibo.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $cliente = Cliente::findOrFail($response->json('data.id'));

    expect($cliente->foto_cliente_path)->not->toBeNull()
        ->and($cliente->foto_dni_path)->not->toBeNull()
        ->and($cliente->foto_dni_reverso_path)->not->toBeNull()
        ->and($cliente->foto_suministro_path)->not->toBeNull()
        ->and($cliente->foto_recibo_luz_path)->not->toBeNull();

    Storage::disk('public')->assertExists($cliente->foto_suministro_path);
    Storage::disk('public')->assertExists($cliente->foto_recibo_luz_path);

    expect($response->json('data.foto_suministro_url'))->toContain('/storage/clientes/')
        ->and($response->json('data.foto_recibo_luz_url'))->toContain('/storage/clientes/');
});

it('replaces an existing photo and deletes the old file on update', function () {
    $cliente = Cliente::factory()->registradoPor($this->admin)->forAgencia($this->agencia)->create([
        'foto_cliente_path' => 'clientes/old-path.jpg',
    ]);
    Storage::disk('public')->put('clientes/old-path.jpg', 'contenido-viejo');

    $this->post("/api/clientes/{$cliente->id}", [
        '_method' => 'PUT',
        'foto_cliente' => UploadedFile::fake()->image('nueva.jpg'),
    ], ['Accept' => 'application/json'])->assertSuccessful();

    Storage::disk('public')->assertMissing('clientes/old-path.jpg');
    expect($cliente->fresh()->foto_cliente_path)->not->toBe('clientes/old-path.jpg');
});

it('replaces the foto de suministro and deletes the old file on update', function () {
    $cliente = Cliente::factory()->registradoPor($this->admin)->forAgencia($this->agencia)->create([
        'foto_suministro_path' => 'clientes/suministro-viejo.jpg',
    ]);
    Storage::disk('public')->put('clientes/suministro-viejo.jpg', 'contenido-viejo');

    $this->putJson("/api/clientes/{$cliente->id}", [
        'foto_suministro' => UploadedFile::fake()->image('suministro-nuevo.jpg'),
    ])->assertSuccessful();

    Storage::disk('public')->assertMissing('clientes/suministro-viejo.jpg');
    expect($cliente->fresh()->foto_suministro_path)->not->toBe('clientes/suministro-viejo.jpg');
});
