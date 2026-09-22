<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\ConfiguracionVenta;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
});

it('allows administrador_general to set the empresa-wide default configuration', function () {
    $admin = User::factory()->forEmpresa($this->empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->putJson('/api/configuraciones-venta', [
        'interes_mensual_default' => 3,
    ])->assertSuccessful()->assertJsonPath('data.agencia_id', null);
});

it('denies administrador_agencia from setting the empresa-wide default (no agencia_id)', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-venta', [
        'interes_mensual_default' => 3,
    ])->assertForbidden();
});

it('allows administrador_agencia to set an override for their own agencia', function () {
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-venta', [
        'agencia_id' => $this->agencia->id,
        'interes_mensual_default' => 2.5,
    ])->assertSuccessful()->assertJsonPath('data.agencia_id', $this->agencia->id);
});

it('denies administrador_agencia from setting an override for another agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->putJson('/api/configuraciones-venta', [
        'agencia_id' => $otraAgencia->id,
        'interes_mensual_default' => 2.5,
    ])->assertForbidden();
});

it('accepts a 0 interest rate (venta a crédito sin interés)', function () {
    $admin = User::factory()->forEmpresa($this->empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->putJson('/api/configuraciones-venta', [
        'interes_mensual_default' => 0,
    ])->assertSuccessful()->assertJsonPath('data.interes_mensual_default', '0.00');
});

it('allows administrador_agencia to delete their own agencia override', function () {
    $config = ConfiguracionVenta::factory()->deAgencia($this->agencia)->create();

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->deleteJson("/api/configuraciones-venta/{$config->id}")->assertSuccessful();

    expect(ConfiguracionVenta::find($config->id))->toBeNull();
});

it('denies administrador_agencia from deleting an override of another agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $config = ConfiguracionVenta::factory()->deAgencia($otraAgencia)->create();

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->deleteJson("/api/configuraciones-venta/{$config->id}")->assertForbidden();

    expect(ConfiguracionVenta::find($config->id))->not->toBeNull();
});

it('denies an asesor without the permission from deleting a configuration', function () {
    $config = ConfiguracionVenta::factory()->deAgencia($this->agencia)->create();

    $asesor = User::factory()->forAgencia($this->agencia)->create();
    $asesor->assignRole('asesor');
    Sanctum::actingAs($asesor, ['*']);

    $this->deleteJson("/api/configuraciones-venta/{$config->id}")->assertForbidden();
});

it('allows administrador_general to delete the empresa-wide default configuration', function () {
    $config = ConfiguracionVenta::factory()->deEmpresa($this->empresa)->create();

    $admin = User::factory()->forEmpresa($this->empresa)->create();
    $admin->assignRole('administrador_general');
    Sanctum::actingAs($admin, ['*']);

    $this->deleteJson("/api/configuraciones-venta/{$config->id}")->assertSuccessful();

    expect(ConfiguracionVenta::find($config->id))->toBeNull();
});

it('denies administrador_agencia from deleting the empresa-wide default configuration', function () {
    $config = ConfiguracionVenta::factory()->deEmpresa($this->empresa)->create();

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');
    Sanctum::actingAs($adminAgencia, ['*']);

    $this->deleteJson("/api/configuraciones-venta/{$config->id}")->assertForbidden();

    expect(ConfiguracionVenta::find($config->id))->not->toBeNull();
});
