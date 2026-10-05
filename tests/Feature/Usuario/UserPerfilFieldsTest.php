<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
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

    $this->departamento = UbigeoDepartamento::create(['codigo' => '15', 'nombre' => 'Lima']);
    $this->provincia = UbigeoProvincia::create([
        'ubigeo_departamento_id' => $this->departamento->id,
        'codigo' => '01',
        'nombre' => 'Lima',
    ]);
    $this->distrito = UbigeoDistrito::create([
        'ubigeo_provincia_id' => $this->provincia->id,
        'codigo' => '01',
        'nombre' => 'Lima',
    ]);

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    Sanctum::actingAs($this->admin, ['*']);
});

it('stores the perfil fields on creation', function () {
    $response = $this->postJson('/api/usuarios', [
        'nombre' => 'Ana',
        'apellido' => 'Torres',
        'dni' => '45000001',
        'email' => 'ana.torres@test.com',
        'password' => 'password123',
        'roles' => ['supervisor'],
        'agencia_id' => $this->agencia->id,
        'telefono' => '999888777',
        'fecha_nacimiento' => '1990-05-14',
        'direccion' => 'Av. Javier Prado 123',
        'referencia' => 'Frente al parque',
        'ubigeo_distrito_id' => $this->distrito->id,
        'latitud' => -12.0431800,
        'longitud' => -77.0282400,
    ])->assertCreated();

    $user = User::query()->findOrFail($response->json('data.id'));

    expect($user->telefono)->toBe('999888777')
        ->and($user->fecha_nacimiento->toDateString())->toBe('1990-05-14')
        ->and($user->direccion)->toBe('Av. Javier Prado 123')
        ->and($user->referencia)->toBe('Frente al parque')
        ->and($user->ubigeo_distrito_id)->toBe($this->distrito->id)
        ->and((float) $user->latitud)->toBe(-12.04318)
        ->and((float) $user->longitud)->toBe(-77.02824);
});

it('exposes distrito, provincia and departamento resolved from the ubigeo relation', function () {
    $user = User::factory()->forEmpresa($this->empresa)->create([
        'ubigeo_distrito_id' => $this->distrito->id,
    ]);

    $this->getJson("/api/usuarios/{$user->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.distrito', $this->distrito->nombre)
        ->assertJsonPath('data.provincia', $this->provincia->nombre)
        ->assertJsonPath('data.departamento', $this->departamento->nombre);
});

it('stores the foto de perfil and the QR de Yape on creation', function () {
    $response = $this->postJson('/api/usuarios', [
        'nombre' => 'Luis',
        'apellido' => 'Vega',
        'dni' => '45000002',
        'email' => 'luis.vega@test.com',
        'password' => 'password123',
        'roles' => ['supervisor'],
        'agencia_id' => $this->agencia->id,
        'foto' => UploadedFile::fake()->image('perfil.jpg'),
        'qr_yape' => UploadedFile::fake()->image('yape.jpg'),
    ])->assertCreated();

    $user = User::query()->findOrFail($response->json('data.id'));

    expect($user->foto_path)->not->toBeNull()
        ->and($user->qr_yape_path)->not->toBeNull();

    Storage::disk('public')->assertExists($user->foto_path);
    Storage::disk('public')->assertExists($user->qr_yape_path);

    expect($response->json('data.foto_url'))->toContain('/storage/usuarios/')
        ->and($response->json('data.qr_yape_url'))->toContain('/storage/usuarios/');
});

it('replaces an existing imagen and deletes the old file on update', function () {
    $user = User::factory()->forAgencia($this->agencia)->create([
        'foto_path' => 'usuarios/vieja.jpg',
    ]);
    Storage::disk('public')->put('usuarios/vieja.jpg', 'contenido-viejo');

    $this->putJson("/api/usuarios/{$user->id}", [
        'foto' => UploadedFile::fake()->image('nueva.jpg'),
    ])->assertSuccessful();

    Storage::disk('public')->assertMissing('usuarios/vieja.jpg');
    expect($user->fresh()->foto_path)->not->toBe('usuarios/vieja.jpg');
});

it('updates the ubicacion and contacto fields without touching the images', function () {
    $user = User::factory()->forAgencia($this->agencia)->create([
        'direccion' => 'Direccion vieja',
    ]);

    $this->putJson("/api/usuarios/{$user->id}", [
        'telefono' => '911222333',
        'fecha_nacimiento' => '1988-01-31',
        'direccion' => 'Calle Las Begonias 45',
        'referencia' => 'A tres cuadras del metro',
        'ubigeo_distrito_id' => $this->distrito->id,
        'latitud' => -13.5,
        'longitud' => -71.9,
    ])->assertSuccessful();

    $user->refresh();

    expect($user->telefono)->toBe('911222333')
        ->and($user->direccion)->toBe('Calle Las Begonias 45')
        ->and($user->referencia)->toBe('A tres cuadras del metro')
        ->and($user->ubigeo_distrito_id)->toBe($this->distrito->id)
        ->and($user->foto_path)->toBeNull();
});

it('rejects an invalid distrito, out of range coordinates and a future birth date', function () {
    $this->postJson('/api/usuarios', [
        'nombre' => 'X',
        'apellido' => 'Y',
        'dni' => '45000003',
        'password' => 'password123',
        'roles' => ['supervisor'],
        'agencia_id' => $this->agencia->id,
        'ubigeo_distrito_id' => 999999,
        'latitud' => 120,
        'longitud' => -400,
        'fecha_nacimiento' => now()->addYear()->toDateString(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['ubigeo_distrito_id', 'latitud', 'longitud', 'fecha_nacimiento']);
});

it('rejects a non image file for the foto de perfil', function () {
    $this->postJson('/api/usuarios', [
        'nombre' => 'X',
        'apellido' => 'Y',
        'dni' => '45000004',
        'password' => 'password123',
        'roles' => ['supervisor'],
        'agencia_id' => $this->agencia->id,
        'foto' => UploadedFile::fake()->create('documento.pdf', 100),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('foto');
});

it('deletes the foto and QR files from disk when the user is destroyed', function () {
    $objetivo = User::factory()->forAgencia($this->agencia)->create();
    $objetivo->assignRole('asesor');
    $supervisor = User::factory()->forAgencia($this->agencia)->create();
    $supervisor->assignRole('supervisor');
    $objetivo->update(['supervisor_id' => $supervisor->id]);

    $this->putJson("/api/usuarios/{$objetivo->id}", [
        'foto' => UploadedFile::fake()->image('perfil.jpg'),
        'qr_yape' => UploadedFile::fake()->image('yape.jpg'),
    ])->assertSuccessful();

    $paths = [$objetivo->fresh()->foto_path, $objetivo->fresh()->qr_yape_path];

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }

    $this->deleteJson("/api/usuarios/{$objetivo->id}")->assertSuccessful();

    foreach ($paths as $path) {
        Storage::disk('public')->assertMissing($path);
    }

    expect(User::find($objetivo->id))->toBeNull();
});
