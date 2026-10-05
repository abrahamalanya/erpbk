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

    // clientes.eliminar no viene para peinadora: el borrado se prueba con el
    // rol que sí puede eliminar (ver ClientePolicyTest).
    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    Sanctum::actingAs($this->admin, ['*']);
});

it('deletes every cliente photo from disk when the cliente is destroyed', function () {
    $response = $this->post('/api/clientes', [
        'nombre' => 'Juan',
        'apellido' => 'Perez',
        'tipo_documento' => 'dni',
        'numero_documento' => '77888888',
        'agencia_id' => $this->agencia->id,
        'foto_cliente' => UploadedFile::fake()->image('cliente.jpg'),
        'foto_dni' => UploadedFile::fake()->image('dni.jpg'),
        'foto_dni_reverso' => UploadedFile::fake()->image('dni-reverso.jpg'),
        'foto_suministro' => UploadedFile::fake()->image('suministro.jpg'),
        'foto_recibo_luz' => UploadedFile::fake()->image('recibo.jpg'),
        'fotos_casa' => [UploadedFile::fake()->image('casa1.jpg'), UploadedFile::fake()->image('casa2.jpg')],
        'fotos_negocio' => [UploadedFile::fake()->image('negocio.jpg')],
        'fotos_adicionales' => [UploadedFile::fake()->image('adicional.jpg')],
    ], ['Accept' => 'application/json'])->assertCreated();

    $id = $response->json('data.id');
    $cliente = Cliente::findOrFail($id);

    $paths = array_filter([
        $cliente->foto_cliente_path, $cliente->foto_dni_path, $cliente->foto_dni_reverso_path,
        $cliente->foto_suministro_path, $cliente->foto_recibo_luz_path,
    ]);

    // 5 columnas + 2 casa + 1 negocio + 1 adicional
    expect($paths)->toHaveCount(5)
        ->and($cliente->fotos)->toHaveCount(4);

    foreach ([...$paths, ...$cliente->fotos->pluck('path')->all()] as $path) {
        Storage::disk('public')->assertExists($path);
    }

    $this->deleteJson("/api/clientes/{$id}")->assertSuccessful();

    foreach ([...$paths, ...$cliente->fotos->pluck('path')->all()] as $path) {
        Storage::disk('public')->assertMissing($path);
    }

    expect(Cliente::find($id))->toBeNull()
        ->and(DB::table('cliente_fotos')->where('cliente_id', $id)->count())->toBe(0);
});

it('destroys a cliente without photos without failing', function () {
    $cliente = Cliente::factory()
        ->registradoPor($this->admin)
        ->forAgencia($this->agencia)
        ->create();

    $this->deleteJson("/api/clientes/{$cliente->id}")->assertSuccessful();

    expect(Cliente::find($cliente->id))->toBeNull();
});
