<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('forces agencia_id/empresa_id from the actor for agencia-level roles even if spoofed', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $agenciaA = Agencia::factory()->for($empresaA)->create();
    $agenciaB = Agencia::factory()->for($empresaB)->create();

    $peinadora = User::factory()->forAgencia($agenciaA)->create();
    $peinadora->assignRole('peinadora');
    Sanctum::actingAs($peinadora, ['*']);

    $response = $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '11111111',
        'agencia_id' => $agenciaB->id,
        'empresa_id' => $empresaB->id,
    ])->assertCreated();

    expect($response->json('data.agencia_id'))->toBe($agenciaA->id)
        ->and($response->json('data.empresa_id'))->toBe($empresaA->id);
});

it('never sets asesor_id via store, regardless of payload', function () {
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();
    $peinadora = User::factory()->forAgencia($agencia)->create();
    $peinadora->assignRole('peinadora');
    $asesor = User::factory()->forAgencia($agencia)->create();
    $asesor->assignRole('asesor');

    Sanctum::actingAs($peinadora, ['*']);

    $response = $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '22222222',
        'asesor_id' => $asesor->id,
    ])->assertCreated();

    expect($response->json('data.asesor_id'))->toBeNull();
});

it('allows the same numero_documento across two different empresas', function () {
    $empresaA = Empresa::factory()->create();
    $empresaB = Empresa::factory()->create();
    $agenciaA = Agencia::factory()->for($empresaA)->create();
    $agenciaB = Agencia::factory()->for($empresaB)->create();

    Cliente::factory()->forAgencia($agenciaA)->create(['numero_documento' => '33333333']);

    $peinadoraB = User::factory()->forAgencia($agenciaB)->create();
    $peinadoraB->assignRole('peinadora');
    Sanctum::actingAs($peinadoraB, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Otro', 'apellido' => 'Cliente', 'tipo_documento' => 'dni', 'numero_documento' => '33333333',
    ])->assertCreated();
});

it('rejects a duplicate numero_documento within the same empresa', function () {
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();

    Cliente::factory()->forAgencia($agencia)->create(['numero_documento' => '44444444']);

    $peinadora = User::factory()->forAgencia($agencia)->create();
    $peinadora->assignRole('peinadora');
    Sanctum::actingAs($peinadora, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Duplicado', 'apellido' => 'Cliente', 'tipo_documento' => 'dni', 'numero_documento' => '44444444',
    ])->assertUnprocessable()->assertJsonValidationErrors('numero_documento');
});

it('requires empresa_id and agencia_id when sistemas creates a cliente', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '55555555',
    ])->assertUnprocessable()->assertJsonValidationErrors(['empresa_id', 'agencia_id']);
});

it('allows sistemas to create a cliente for any empresa/agencia when both are provided', function () {
    $empresa = Empresa::factory()->create();
    $agencia = Agencia::factory()->for($empresa)->create();

    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    Sanctum::actingAs($sistemas, ['*']);

    $this->postJson('/api/clientes', [
        'nombre' => 'Juan', 'apellido' => 'Perez', 'tipo_documento' => 'dni', 'numero_documento' => '66666666',
        'empresa_id' => $empresa->id, 'agencia_id' => $agencia->id,
    ])->assertCreated();
});
