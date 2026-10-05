<?php

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

    $this->sistemas = User::factory()->create();
    $this->sistemas->assignRole('sistemas');
    Sanctum::actingAs($this->sistemas, ['*']);
});

it('deletes the logo and firma files when an empresa is destroyed', function () {
    $response = $this->post('/api/empresas', [
        'nombre' => 'Empresa Con Logo',
        'logo' => UploadedFile::fake()->image('logo.png'),
        'firma' => UploadedFile::fake()->image('firma.png'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $id = $response->json('data.id');
    $empresa = Empresa::findOrFail($id);

    Storage::disk('public')->assertExists($empresa->logo_path);
    Storage::disk('public')->assertExists($empresa->firma_path);

    $this->deleteJson("/api/empresas/{$id}")->assertSuccessful();

    Storage::disk('public')->assertMissing($empresa->logo_path);
    Storage::disk('public')->assertMissing($empresa->firma_path);
});

it('destroys an empresa that never had images without failing', function () {
    $empresa = Empresa::factory()->create();

    $this->deleteJson("/api/empresas/{$empresa->id}")->assertSuccessful();

    expect(Empresa::find($empresa->id))->toBeNull();
});
