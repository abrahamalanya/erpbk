<?php

use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->agencia = Agencia::factory()->create();
    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');
    $this->dina = User::factory()->forAgencia($this->agencia)->create();
    $this->dina->assignRole('asesor');
    $this->tatiana = User::factory()->forAgencia($this->agencia)->create();
    $this->tatiana->assignRole('asesor');
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    $this->credito = Credito::factory()->paraBien($bien)->create(['registrado_por' => $this->dina->id]);
    $this->url = "/api/creditos-prendarios/{$this->credito->id}/cambiar-registrado-por";
    Sanctum::actingAs($this->admin, ['*']);
});

it('reassigns visibility to the selected adviser', function () {
    $this->getJson("/api/creditos-prendarios/{$this->credito->id}/asesores-registrado-por")
        ->assertOk()->assertJsonCount(2, 'data');
    $this->postJson($this->url, [
        'registrado_por' => $this->tatiana->id, 'registrado_por_actual' => $this->dina->id,
    ])->assertOk()->assertJsonPath('data.registrado_por.id', $this->tatiana->id);
    Sanctum::actingAs($this->tatiana, ['*']);
    $this->getJson('/api/creditos-prendarios')->assertOk()->assertJsonCount(1, 'data.data');
    Sanctum::actingAs($this->dina, ['*']);
    $this->getJson('/api/creditos-prendarios')->assertOk()->assertJsonCount(0, 'data.data');
});

it('rejects advisers changing ownership and administrators from other agencies', function () {
    $otraAgencia = Agencia::factory()->create(['empresa_id' => $this->agencia->empresa_id]);
    foreach ([$this->dina, tap(User::factory()->forAgencia($otraAgencia)->create(), fn ($user) => $user->assignRole('administrador_agencia'))] as $actor) {
        Sanctum::actingAs($actor, ['*']);
        $this->postJson($this->url, [
            'registrado_por' => $this->tatiana->id, 'registrado_por_actual' => $this->dina->id,
        ])->assertForbidden();
    }
});

it('rejects ineligible recipients', function (string $invalid) {
    match ($invalid) {
        'inactive' => $this->tatiana->update(['estado' => 'inactivo']),
        'agency' => $this->tatiana->update(['agencia_id' => Agencia::factory()->create()->id]),
        'module' => $this->tatiana->update(['modulos' => []]),
        'role' => $this->tatiana->syncRoles(['secretaria']),
    };
    $this->postJson($this->url, [
        'registrado_por' => $this->tatiana->id, 'registrado_por_actual' => $this->dina->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('registrado_por');
    expect($this->credito->fresh()->registrado_por)->toBe($this->dina->id);
})->with(['inactive', 'agency', 'module', 'role']);

it('prevents overwriting a concurrent reassignment', function () {
    $this->postJson($this->url, [
        'registrado_por' => $this->tatiana->id, 'registrado_por_actual' => $this->tatiana->id,
    ])->assertConflict();
    expect($this->credito->fresh()->registrado_por)->toBe($this->dina->id);
});

it('reassigns only the selected renewal without changing its predecessor or terms', function () {
    $refrendo = Credito::factory()->create([
        'empresa_id' => $this->agencia->empresa_id, 'agencia_id' => $this->agencia->id,
        'registrado_por' => $this->dina->id, 'refrendo_de_credito_id' => $this->credito->id,
        'numero_refrendo' => 1, 'estado' => 'activo',
    ]);
    $antes = $refrendo->fresh()->getAttributes();
    $this->postJson("/api/creditos-prendarios/{$refrendo->id}/cambiar-registrado-por", [
        'registrado_por' => $this->tatiana->id, 'registrado_por_actual' => $this->dina->id,
    ])->assertOk();
    $despues = $refrendo->fresh()->getAttributes();
    unset($antes['registrado_por'], $antes['updated_at'], $despues['registrado_por'], $despues['updated_at']);
    expect($despues)->toEqual($antes)
        ->and($this->credito->fresh()->registrado_por)->toBe($this->dina->id);
});
