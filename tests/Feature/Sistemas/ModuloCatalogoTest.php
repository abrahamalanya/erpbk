<?php

use App\Modules\Sistemas\Models\Modulo;
use App\Modules\Sistemas\Models\PerfilAcceso;
use App\Modules\Sistemas\Models\Role;
use App\Modules\Sistemas\Services\ModuloService;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->sistemas = User::factory()->create();
    $this->sistemas->assignRole('sistemas');
    $this->nuevo = Modulo::query()->create(['key' => 'inventario_nuevo', 'nombre' => 'Inventario nuevo', 'grupo' => 'ventas']);
});

it('restricts module management while preserving catalog reads for user administrators', function () {
    $admin = User::factory()->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin);
    $this->getJson('/api/modulos')->assertSuccessful();
    $this->getJson("/api/modulos/{$this->nuevo->id}")->assertForbidden();
    $this->postJson('/api/modulos', ['key' => 'otro', 'nombre' => 'Otro'])->assertForbidden();
    $this->putJson("/api/modulos/{$this->nuevo->id}", ['nombre' => 'Cambiar', 'revision' => $this->nuevo->revision])->assertForbidden();
    $this->deleteJson("/api/modulos/{$this->nuevo->id}", ['revision' => $this->nuevo->revision])->assertForbidden();
});

it('registers a module in the shared catalog without automatically granting it to profiles or roles', function () {
    $perfil = PerfilAcceso::query()->create(['nombre' => 'Actual', 'activo' => true]);
    $role = Role::where('name', 'asesor')->firstOrFail();
    $before = $role->permissions()->pluck('name')->all();
    Sanctum::actingAs($this->sistemas);
    $this->postJson('/api/modulos', ['key' => 'inventario', 'nombre' => 'Inventario', 'grupo' => 'ventas'])->assertCreated()->assertJsonPath('data.key', 'inventario');
    $this->getJson('/api/modulos')->assertJsonFragment(['key' => 'inventario']);
    expect($perfil->modulos()->count())->toBe(0)
        ->and($role->modulos()->where('key', 'inventario')->exists())->toBeFalse()
        ->and($role->permissions()->pluck('name')->all())->toBe($before);
    expect(app(ModuloService::class)->modulosEfectivos($this->sistemas))->toContain('inventario');
});

it('rejects duplicate, reserved and malformed module keys', function (string $key) {
    Sanctum::actingAs($this->sistemas);
    $this->postJson('/api/modulos', ['key' => $key, 'nombre' => 'Prueba'])->assertUnprocessable()->assertJsonValidationErrors('key');
})->with(['diario', '__denegado', 'Inventario', 'ruta/modulo', 'modulo con espacios', '1inventario']);

it('updates module labels without changing keys or existing profile relationships', function () {
    $perfil = PerfilAcceso::query()->create(['nombre' => 'Actual', 'activo' => true]);
    $perfil->modulos()->attach($this->nuevo);
    Sanctum::actingAs($this->sistemas);
    $this->putJson("/api/modulos/{$this->nuevo->id}", ['nombre' => 'Stock', 'grupo' => 'almacen', 'revision' => $this->nuevo->revision])->assertSuccessful()->assertJsonPath('data.key', 'inventario_nuevo');
    $this->getJson("/api/perfiles-acceso/{$perfil->id}")->assertJsonPath('data.modulos.0.nombre', 'Stock');
    $this->putJson("/api/modulos/{$this->nuevo->id}", ['key' => 'renombrado', 'nombre' => 'Stock', 'revision' => $this->nuevo->fresh()->revision])->assertUnprocessable()->assertJsonValidationErrors('key');
});

it('protects the technical identity outside the HTTP controller too', function () {
    expect(fn () => $this->nuevo->update(['key' => 'renombrado']))->toThrow(DomainException::class);
});

it('rejects stale edits and deletes even when updates happen within the same second', function () {
    Sanctum::actingAs($this->sistemas);
    $old = $this->nuevo->revision;
    $this->putJson("/api/modulos/{$this->nuevo->id}", ['nombre' => 'Actualizado', 'grupo' => 'ventas', 'revision' => $old])->assertSuccessful();
    $this->putJson("/api/modulos/{$this->nuevo->id}", ['nombre' => 'Sobrescrito', 'revision' => $old])->assertStatus(409);
    $this->deleteJson("/api/modulos/{$this->nuevo->id}", ['revision' => $old])->assertStatus(409);
    expect($this->nuevo->fresh()->nombre)->toBe('Actualizado');
});

it('shows references and prevents deletion while a profile uses a module', function () {
    $perfil = PerfilAcceso::query()->create(['nombre' => 'Inventario', 'activo' => true]);
    $perfil->modulos()->attach($this->nuevo);
    Sanctum::actingAs($this->sistemas);
    $this->getJson("/api/modulos/{$this->nuevo->id}")->assertSuccessful()->assertJsonPath('data.uso.perfiles.0.nombre', 'Inventario')->assertJsonPath('data.uso.puede_eliminar', false);
    $this->deleteJson("/api/modulos/{$this->nuevo->id}", ['revision' => $this->nuevo->revision])->assertStatus(409);
    expect($perfil->modulos()->count())->toBe(1);
});

it('protects role defaults and legacy individual module selections from deletion', function () {
    $role = Role::where('name', 'asesor')->firstOrFail();
    $role->modulos()->attach($this->nuevo);
    Sanctum::actingAs($this->sistemas);
    $this->deleteJson("/api/modulos/{$this->nuevo->id}", ['revision' => $this->nuevo->revision])->assertStatus(409);
    $role->modulos()->detach($this->nuevo);
    $user = User::factory()->create(['modulos' => [$this->nuevo->key]]);
    $this->getJson("/api/modulos/{$this->nuevo->id}")->assertJsonPath('data.uso.selecciones_individuales', 1);
    $this->deleteJson("/api/modulos/{$this->nuevo->id}", ['revision' => $this->nuevo->revision])->assertStatus(409);
    expect($user->fresh()->modulos)->toBe([$this->nuevo->key]);
});

it('protects modules wired to API routes even without profile or role assignments', function () {
    $modulo = Modulo::where('key', 'diario')->firstOrFail();
    $modulo->roles()->detach();
    Sanctum::actingAs($this->sistemas);
    $this->getJson("/api/modulos/{$modulo->id}")->assertSuccessful()->assertJsonFragment(['metodo' => 'POST', 'ruta' => '/api/creditos-diarios'])->assertJsonPath('data.uso.puede_eliminar', false);
    $this->deleteJson("/api/modulos/{$modulo->id}", ['revision' => $modulo->revision])->assertStatus(409);
});

it('deletes only unused unconnected modules', function () {
    Sanctum::actingAs($this->sistemas);
    $this->getJson("/api/modulos/{$this->nuevo->id}")->assertJsonPath('data.uso.puede_eliminar', true);
    $this->deleteJson("/api/modulos/{$this->nuevo->id}", ['revision' => $this->nuevo->revision])->assertSuccessful();
    $this->assertDatabaseMissing('modulos', ['id' => $this->nuevo->id]);
});

it('keeps visual catalog edits when the permission seeder is rerun', function () {
    Modulo::where('key', 'diario')->firstOrFail()->update(['nombre' => 'Diarios personalizados', 'grupo' => 'operaciones']);
    $this->seed(PermissionSeeder::class);
    expect(Modulo::where('key', 'diario')->firstOrFail()->only(['nombre', 'grupo']))->toBe(['nombre' => 'Diarios personalizados', 'grupo' => 'operaciones']);
});

it('does not silently drop modules deleted between request validation and assignment', function () {
    $this->nuevo->delete();
    expect(fn () => app(ModuloService::class)->asignarARol(Role::where('name', 'asesor')->firstOrFail(), ['inventario_nuevo']))->toThrow(ValidationException::class);
});
