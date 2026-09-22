<?php

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');
    Sanctum::actingAs($this->admin, ['*']);
});

it('lists published and retirado products, scoped to the actor’s agencia', function () {
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta']);
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'retirado_venta']);
    Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia']);

    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    Bien::factory()->forAgencia($otraAgencia)->create(['estado' => 'disponible_venta']);

    $response = $this->getJson('/api/tienda-productos')->assertSuccessful();

    expect($response->json('data.total'))->toBe(2);
});

it('updates the precio_venta and precio_oferta of a published bien', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 500]);

    $this->patchJson("/api/tienda-productos/bien/{$bien->id}", [
        'precio_venta' => 600,
        'precio_oferta' => 450,
    ])->assertSuccessful()
        ->assertJsonPath('data.precio_venta', '600.00')
        ->assertJsonPath('data.precio_oferta', '450.00');

    expect($bien->fresh()->precio_venta)->toBe('600.00')
        ->and($bien->fresh()->precio_oferta)->toBe('450.00');
});

it('rejects a precio_oferta greater than or equal to the precio_venta', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 500]);

    $this->patchJson("/api/tienda-productos/bien/{$bien->id}", ['precio_oferta' => 500])
        ->assertUnprocessable();
});

it('retires a product from the tienda without deleting the record', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 500]);

    $this->postJson("/api/tienda-productos/bien/{$bien->id}/retirar")
        ->assertSuccessful()
        ->assertJsonPath('data.articulo_tipo', 'bien');

    expect(Bien::find($bien->id))->not->toBeNull()
        ->and($bien->fresh()->estado)->toBe('retirado_venta');
});

it('republishes a retired product by setting its estado back to disponible_venta', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'retirado_venta', 'precio_venta' => 500]);

    $this->patchJson("/api/tienda-productos/bien/{$bien->id}", ['estado' => 'disponible_venta'])
        ->assertSuccessful();

    expect($bien->fresh()->estado)->toBe('disponible_venta');
});

it('rejects editing a bien that is not published nor previously retired', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'en_garantia', 'precio_venta' => 500]);

    $this->patchJson("/api/tienda-productos/bien/{$bien->id}", ['precio_venta' => 600])
        ->assertUnprocessable();
});

it('denies an asesor without bienes.editar from editing a tienda product', function () {
    $asesor = User::factory()->forAgencia($this->agencia)->create();
    // El rol asesor tiene bienes.editar (GARANTIA_FORMAL_PERMISSIONS aplicado
    // a supervisor/asesor vía PermissionSeeder), así que forzamos un rol sin él.
    $asesor->assignRole('peinadora');
    Sanctum::actingAs($asesor, ['*']);

    $bien = Bien::factory()->forAgencia($this->agencia)->create(['estado' => 'disponible_venta', 'precio_venta' => 500]);

    $this->patchJson("/api/tienda-productos/bien/{$bien->id}", ['precio_venta' => 600])
        ->assertForbidden();
});
