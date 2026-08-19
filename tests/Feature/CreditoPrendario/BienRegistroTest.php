<?php

use App\Modules\Cliente\Models\Cliente;
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
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
});

it('requires marca and modelo for tipo electro', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/bienes', [
        'cliente_id' => $this->cliente->id,
        'tipo' => 'electro',
        'nombre' => 'Refrigeradora',
        'valorizacion' => 800,
        'puntaje' => 7,
    ])->assertUnprocessable()->assertJsonValidationErrors(['marca', 'modelo']);
});

it('does not require marca and modelo for tipo varios', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/bienes', [
        'cliente_id' => $this->cliente->id,
        'tipo' => 'varios',
        'nombre' => 'Anillo de oro',
        'valorizacion' => 300,
        'puntaje' => 7,
    ])->assertCreated();
});

it('derives empresa_id/agencia_id from the cliente when registering a bien', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $response = $this->postJson('/api/bienes', [
        'cliente_id' => $this->cliente->id,
        'tipo' => 'electro',
        'nombre' => 'Lavadora',
        'marca' => 'Samsung',
        'modelo' => 'WA-100',
        'valorizacion' => 1200,
        'puntaje' => 8,
    ])->assertCreated();

    expect($response->json('data.agencia_id'))->toBe($this->agencia->id)
        ->and($response->json('data.empresa_id'))->toBe($this->empresa->id)
        ->and($response->json('data.cliente_id'))->toBe($this->cliente->id)
        ->and($response->json('data.estado'))->toBe('en_garantia');
});

it('allows the registering asesor to view the bien via show', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    $clienteDelAsesor = Cliente::factory()->asignadoA($asesor)->create();
    Sanctum::actingAs($asesor, ['*']);

    $bienId = $this->postJson('/api/bienes', [
        'cliente_id' => $clienteDelAsesor->id,
        'tipo' => 'varios', 'nombre' => 'Anillo de oro', 'valorizacion' => 300, 'puntaje' => 7,
    ])->assertCreated()->json('data.id');

    $this->getJson("/api/bienes/{$bienId}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $bienId);
});

it('requires cliente_id to register a bien', function () {
    $admin = User::factory()->forEmpresa($this->empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson('/api/bienes', [
        'tipo' => 'varios',
        'nombre' => 'Herramienta',
        'valorizacion' => 100,
        'puntaje' => 5,
    ])->assertUnprocessable()->assertJsonValidationErrors(['cliente_id']);
});

it('requires puntaje between 1 and 10 to register a bien', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/bienes', [
        'cliente_id' => $this->cliente->id,
        'tipo' => 'varios',
        'nombre' => 'Anillo de oro',
        'valorizacion' => 300,
    ])->assertUnprocessable()->assertJsonValidationErrors(['puntaje']);

    $this->postJson('/api/bienes', [
        'cliente_id' => $this->cliente->id,
        'tipo' => 'varios',
        'nombre' => 'Anillo de oro',
        'valorizacion' => 300,
        'puntaje' => 11,
    ])->assertUnprocessable()->assertJsonValidationErrors(['puntaje']);
});

it('stores a video for the bien and exposes its url', function () {
    Storage::fake('public');

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $video = UploadedFile::fake()->create('estado-producto.mp4', 5000, 'video/mp4');

    $response = $this->postJson('/api/bienes', [
        'cliente_id' => $this->cliente->id,
        'tipo' => 'varios',
        'nombre' => 'Anillo de oro',
        'valorizacion' => 300,
        'puntaje' => 7,
        'video' => $video,
    ])->assertCreated();

    expect($response->json('data.video_url'))->not->toBeNull();

    $bien = Bien::query()->findOrFail($response->json('data.id'));
    Storage::disk('public')->assertExists($bien->video_path);
});

it('denies registering a bien for a cliente outside the actor\'s agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroCliente = Cliente::factory()->forAgencia($otraAgencia)->create();

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->postJson('/api/bienes', [
        'cliente_id' => $otroCliente->id,
        'tipo' => 'varios',
        'nombre' => 'Herramienta',
        'valorizacion' => 100,
        'puntaje' => 5,
    ])->assertUnprocessable()->assertJsonValidationErrors(['cliente_id']);
});
