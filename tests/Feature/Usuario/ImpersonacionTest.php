<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\Impersonacion;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresaA = Empresa::factory()->create();
    $this->empresaB = Empresa::factory()->create();
    $this->agenciaA1 = Agencia::factory()->for($this->empresaA)->create();
});

it('allows sistemas to impersonate any role, even cross-empresa', function () {
    $sistemas = User::factory()->forEmpresa($this->empresaA)->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $target = User::factory()->forEmpresa($this->empresaB)->create();
    $target->assignRole('asesor');

    $response = $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertSuccessful();

    expect($response->json('data.access_token'))->not->toBeEmpty()
        ->and($response->json('data.usuario.id'))->toBe($target->id);

    $this->assertDatabaseHas('impersonaciones', [
        'iniciado_por' => $sistemas->id,
        'impersonado_id' => $target->id,
        'finalizado_at' => null,
    ]);
});

it('allows administrador_general to impersonate an assignable role within their own empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forAgencia($this->agenciaA1)->create();
    $target->assignRole('asesor');

    $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertSuccessful();
});

it('forbids administrador_general from impersonating another administrador_general', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forEmpresa($this->empresaA)->create();
    $target->assignRole('administrador_general');

    $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertForbidden();
});

it('forbids administrador_general from impersonating sistemas', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forEmpresa($this->empresaA)->create();
    $target->assignRole('sistemas');

    $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertForbidden();
});

it('returns 404 (tenant-scoped) when administrador_general targets a user in another empresa', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $target = User::factory()->forEmpresa($this->empresaB)->create();
    $target->assignRole('secretaria');

    $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertNotFound();
});

it('blocks impersonating sistemas even by another sistemas', function () {
    $sistemas = User::factory()->forEmpresa($this->empresaA)->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $target = User::factory()->forEmpresa($this->empresaA)->create();
    $target->assignRole('sistemas');

    $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertStatus(422);
});

it('blocks self-impersonation', function () {
    $sistemas = User::factory()->forEmpresa($this->empresaA)->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->postJson("/api/usuarios/{$sistemas->id}/impersonar")->assertStatus(422);
});

it('blocks impersonating an inactive user', function () {
    $sistemas = User::factory()->forEmpresa($this->empresaA)->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $target = User::factory()->forEmpresa($this->empresaA)->create(['estado' => 'inactivo']);
    $target->assignRole('asesor');

    $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertStatus(422);
});

it('blocks starting a second impersonation while already impersonating (anti-nesting)', function () {
    $sistemas = User::factory()->forEmpresa($this->empresaA)->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $target1 = User::factory()->forEmpresa($this->empresaA)->create();
    $target1->assignRole('asesor');
    $target2 = User::factory()->forEmpresa($this->empresaA)->create();
    $target2->assignRole('asesor');

    $this->postJson("/api/usuarios/{$target1->id}/impersonar")->assertSuccessful();

    // Sigue autenticado con el token ORIGINAL del sistemas (Sanctum::actingAs
    // no cambia), que es justo el caso que la regla anti-anidamiento cubre.
    $this->postJson("/api/usuarios/{$target2->id}/impersonar")->assertStatus(422);
});

it('blocks impersonating a target that is already being impersonated by someone else', function () {
    $sistemas = User::factory()->forEmpresa($this->empresaA)->create();
    $sistemas->assignRole('sistemas');

    $target = User::factory()->forEmpresa($this->empresaA)->create();
    $target->assignRole('asesor');

    Impersonacion::factory()->create([
        'empresa_id' => $target->empresa_id,
        'iniciado_por' => User::factory()->forEmpresa($this->empresaA)->create()->id,
        'impersonado_id' => $target->id,
        'token_id' => 999999,
    ]);

    Sanctum::actingAs($sistemas, ['*']);

    $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertStatus(422);
});

it('denies a role without usuarios.impersonar from impersonating', function () {
    $secretaria = User::factory()->forEmpresa($this->empresaA)->create();
    $secretaria->assignRole('secretaria');
    Sanctum::actingAs($secretaria, ['*']);

    $target = User::factory()->forEmpresa($this->empresaA)->create();
    $target->assignRole('asesor');

    $this->postJson("/api/usuarios/{$target->id}/impersonar")->assertForbidden();
});

// Las pruebas de abajo necesitan que el token devuelto por "iniciar" AUTENTIQUE
// de verdad en la siguiente request. Sanctum::actingAs() fuerza la identidad
// del usuario para TODA la prueba sin mirar el header Authorization, así que
// no sirve para probar el intercambio real de tokens — en su lugar se crea
// un token real (como hace login) y se manda explícitamente en cada request.
// Auth::forgetGuards() es obligatorio entre dos requests con tokens distintos
// dentro del mismo test: el guard "sanctum" cachea el usuario resuelto en la
// PRIMERA request y lo reutiliza en las siguientes si no se limpia, así que
// sin esto la segunda request "vería" al usuario de la primera sin importar
// qué token se mande.

it('makes auth/me resolve to the impersonated target, not the admin', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    $adminToken = $admin->createToken('test')->plainTextToken;

    $target = User::factory()->forAgencia($this->agenciaA1)->create();
    $target->assignRole('asesor');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/usuarios/{$target->id}/impersonar")
        ->assertSuccessful();
    $impersonationToken = $response->json('data.access_token');

    Auth::forgetGuards();
    $me = $this->withHeader('Authorization', "Bearer {$impersonationToken}")
        ->getJson('/api/auth/me')
        ->assertSuccessful();

    expect($me->json('data.id'))->toBe($target->id)
        ->and($me->json('data.permission_names'))->not->toContain('usuarios.eliminar')
        ->and($me->json('data.impersonacion.activa'))->toBeTrue()
        ->and($me->json('data.impersonacion.actor_real.id'))->toBe($admin->id);

    Auth::forgetGuards();
    $meNormal = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/auth/me')
        ->assertSuccessful();
    expect($meNormal->json('data.impersonacion'))->toBeNull();
});

it('ends the impersonation and revokes its token on finalizar', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    $adminToken = $admin->createToken('test')->plainTextToken;

    $target = User::factory()->forAgencia($this->agenciaA1)->create();
    $target->assignRole('asesor');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/usuarios/{$target->id}/impersonar")
        ->assertSuccessful();
    $impersonationToken = $response->json('data.access_token');

    Auth::forgetGuards();
    $this->withHeader('Authorization', "Bearer {$impersonationToken}")
        ->postJson('/api/impersonaciones/finalizar')
        ->assertSuccessful();

    $this->assertDatabaseMissing('impersonaciones', ['impersonado_id' => $target->id, 'finalizado_at' => null]);

    Auth::forgetGuards();
    $this->withHeader('Authorization', "Bearer {$impersonationToken}")
        ->getJson('/api/auth/me')
        ->assertUnauthorized();
});

it('returns 404 from finalizar when there is no active impersonation on the current token', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->postJson('/api/impersonaciones/finalizar')->assertStatus(404);
});

it('ends the impersonation bookkeeping when the impersonated session calls logout instead of finalizar', function () {
    $admin = User::factory()->forEmpresa($this->empresaA)->create();
    $admin->assignRole('administrador_general');
    $adminToken = $admin->createToken('test')->plainTextToken;

    $target = User::factory()->forAgencia($this->agenciaA1)->create();
    $target->assignRole('asesor');

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/usuarios/{$target->id}/impersonar")
        ->assertSuccessful();
    $impersonationToken = $response->json('data.access_token');

    Auth::forgetGuards();
    $this->withHeader('Authorization', "Bearer {$impersonationToken}")
        ->postJson('/api/auth/logout')
        ->assertSuccessful();

    $this->assertDatabaseMissing('impersonaciones', ['impersonado_id' => $target->id, 'finalizado_at' => null]);
});
