<?php

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Tienda\Models\InteresArticulo;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

it('lists only bienes en estado disponible_venta, without leaking cliente info', function () {
    $enVenta = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'nombre' => 'Televisor 50"']);
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia']);
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'recuperado']);

    $response = $this->getJson('/api/tienda/bienes')->assertSuccessful();

    $ids = collect($response->json('data.data'))->pluck('id');
    expect($ids)->toHaveCount(1)
        ->and($ids->first())->toBe($enVenta->id);

    $item = $response->json('data.data.0');
    expect($item)->toHaveKey('nombre')
        ->and($item)->not->toHaveKey('cliente_id')
        ->and($item)->not->toHaveKey('cliente')
        ->and($item)->not->toHaveKey('registrado_por')
        ->and($item)->not->toHaveKey('observacion');
});

it('filters the public listing by tipo', function () {
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'tipo' => 'electro']);
    Bien::factory()->forAgencia($this->agencia)->varios()->create(['estado' => 'disponible_venta']);

    $response = $this->getJson('/api/tienda/bienes?tipo=varios')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.tipo'))->toBe('varios');
});

it('shows the detail of a bien disponible_venta without authentication', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);

    $this->getJson("/api/tienda/bienes/{$bien->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.id', $bien->id);
});

it('returns 404 for a bien that is not disponible_venta', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia']);

    $this->getJson("/api/tienda/bienes/{$bien->id}")->assertNotFound();
});

it('creates an interes and notifies the administrador_agencia', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);

    $this->postJson("/api/tienda/bienes/{$bien->id}/interes", [
        'nombre' => 'Juan Pérez',
        'telefono' => '999888777',
        'mensaje' => 'Me interesa, ¿sigue disponible?',
    ])->assertCreated();

    expect(InteresArticulo::where('articulo_type', 'bien')->where('articulo_id', $bien->id)->where('nombre', 'Juan Pérez')->exists())->toBeTrue();

    $this->assertDatabaseCount('notifications', 1);
    expect($this->adminAgencia->notifications()->count())->toBe(1);
});

it('rejects interes without nombre or telefono', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);

    $this->postJson("/api/tienda/bienes/{$bien->id}/interes", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre', 'telefono']);
});

it('rejects interes on a bien that is not disponible_venta', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia']);

    $this->postJson("/api/tienda/bienes/{$bien->id}/interes", [
        'nombre' => 'Juan Pérez',
        'telefono' => '999888777',
    ])->assertNotFound();
});
