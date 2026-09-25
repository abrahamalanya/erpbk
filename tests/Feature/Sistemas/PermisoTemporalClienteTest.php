<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\PermisoTemporal;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    $this->cliente = Cliente::factory()->asignadoA($this->asesor)->create();
});

function concederPermisoEnTest($cliente, $admin, string $motivo = 'Corrección autorizada por el supervisor')
{
    Sanctum::actingAs($admin, ['*']);

    return test()->postJson("/api/gestion/permisos-temporales/{$cliente->id}", [
        'motivo' => $motivo,
    ]);
}

it('quita clientes.editar al rol asesor por defecto', function () {
    expect($this->asesor->fresh()->getAllPermissions()->pluck('name')->all())
        ->not->toContain('clientes.editar')
        ->and($this->admin->fresh()->getAllPermissions()->pluck('name')->all())
        ->toContain('gestion.permisos_temporales');
});

it('concede al asesor una hora de edición para un cliente concreto', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->putJson("/api/clientes/{$this->cliente->id}", ['telefono' => '111111111'])->assertForbidden();

    $response = concederPermisoEnTest($this->cliente, $this->admin)->assertCreated();

    expect($response->json('data.cliente.id'))->toBe($this->cliente->id)
        ->and($response->json('data.usuario.id'))->toBe($this->asesor->id)
        ->and($response->json('data.concedido_por_usuario.id'))->toBe($this->admin->id)
        ->and($response->json('data.estado'))->toBe('vigente')
        ->and($response->json('data.motivo'))->toBe('Corrección autorizada por el supervisor')
        ->and($response->json('data.expira_at'))->not->toBeNull();

    $this->getJson('/api/gestion/permisos-temporales')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.cliente.id', $this->cliente->id)
        ->assertJsonPath('data.data.0.concedido_por_usuario.id', $this->admin->id);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->putJson("/api/clientes/{$this->cliente->id}", ['telefono' => '999999999'])
        ->assertSuccessful()
        ->assertJsonPath('data.telefono', '999999999');

    $this->getJson('/api/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('data.permisos_temporales_clientes.0.cliente_id', $this->cliente->id);
});

it('no permite una segunda concesión mientras la primera siga vigente y sí permite revocarla', function () {
    $grant = concederPermisoEnTest($this->cliente, $this->admin)->assertCreated()->json('data');

    $this->postJson("/api/gestion/permisos-temporales/{$this->cliente->id}", [
        'motivo' => 'Intento duplicado',
    ])->assertUnprocessable();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->deleteJson("/api/gestion/permisos-temporales/{$grant['id']}", [
        'motivo' => 'La solicitud ya no es necesaria',
    ])->assertForbidden();

    Sanctum::actingAs($this->admin, ['*']);
    $this->deleteJson("/api/gestion/permisos-temporales/{$grant['id']}", [
        'motivo' => 'La solicitud ya no es necesaria',
    ])->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->putJson("/api/clientes/{$this->cliente->id}", ['telefono' => '888888888'])
        ->assertForbidden();

    concederPermisoEnTest($this->cliente, $this->admin, 'Nueva solicitud después de revocar')->assertCreated();
});

it('bloquea al asesor cuando la concesión expira y permite emitir otra después', function () {
    $grant = concederPermisoEnTest($this->cliente, $this->admin)->assertCreated()->json('data');
    PermisoTemporal::query()->findOrFail($grant['id'])->update(['expira_at' => now()->subMinute()]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->putJson("/api/clientes/{$this->cliente->id}", ['telefono' => '777777777'])->assertForbidden();

    concederPermisoEnTest($this->cliente, $this->admin, 'Nueva concesión después de expirar')->assertCreated();
});

it('invalida para el asesor anterior y exige una nueva concesión si se reasigna el cliente', function () {
    $nuevoAsesor = User::factory()->forAgencia($this->agencia)->create();
    $nuevoAsesor->assignRole('asesor');

    $grant = concederPermisoEnTest($this->cliente, $this->admin)->assertCreated()->json('data');
    $this->cliente->update(['asesor_id' => $nuevoAsesor->id]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->putJson("/api/clientes/{$this->cliente->id}", ['telefono' => '111111111'])->assertForbidden();

    Sanctum::actingAs($nuevoAsesor, ['*']);
    $this->putJson("/api/clientes/{$this->cliente->id}", ['telefono' => '222222222'])->assertForbidden();

    Sanctum::actingAs($this->admin, ['*']);
    $this->postJson("/api/gestion/permisos-temporales/{$this->cliente->id}", [
        'motivo' => 'Intento para el nuevo asesor',
    ])->assertUnprocessable();

    $this->deleteJson("/api/gestion/permisos-temporales/{$grant['id']}", [
        'motivo' => 'Reasignación del cliente',
    ])->assertSuccessful();
    $this->postJson("/api/gestion/permisos-temporales/{$this->cliente->id}", [
        'motivo' => 'Permiso para el nuevo asesor',
    ])->assertCreated();

    Sanctum::actingAs($nuevoAsesor, ['*']);
    $this->putJson("/api/clientes/{$this->cliente->id}", ['telefono' => '333333333'])->assertSuccessful();
});

it('no concede permisos a clientes sin asesor asignado', function () {
    $sinAsesor = Cliente::factory()->forAgencia($this->agencia)->create();
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson("/api/gestion/permisos-temporales/{$sinAsesor->id}", [
        'motivo' => 'Intento sobre cliente sin asesor',
    ])->assertUnprocessable()
        ->assertJsonPath('message', 'El cliente debe tener un asesor asignado para conceder este permiso.');
});

it('solo permite administrar permisos a sistemas y administrador_general', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->getJson('/api/gestion/permisos-temporales')->assertForbidden();
    $this->postJson("/api/gestion/permisos-temporales/{$this->cliente->id}", [
        'motivo' => 'No debería concederse',
    ])->assertForbidden();
});
