<?php

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Models\Banco;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->administradorAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->administradorAgencia->assignRole('administrador_agencia');

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    $administradorGeneral = User::factory()->forEmpresa($this->empresa)->create();
    $administradorGeneral->assignRole('administrador_general');
    Sanctum::actingAs($administradorGeneral, ['*']);
    $this->getJson('/api/bovedas')->assertSuccessful();
    $bovedaPrincipalId = Boveda::query()->where('empresa_id', $this->empresa->id)->where('tipo', 'principal')->firstOrFail()->id;
    $this->bovedaAgencia = Boveda::query()->where('agencia_id', $this->agencia->id)->firstOrFail();
    $this->postJson("/api/bovedas/{$bovedaPrincipalId}/aperturar", ['saldo_inicial' => 1000])->assertCreated();
    $this->postJson("/api/bovedas/{$this->bovedaAgencia->id}/inyectar", ['monto' => 1000])->assertCreated();

    $this->cuenta = CuentaBancaria::factory()->paraBoveda($this->bovedaAgencia)->create([
        'banco_id' => Banco::factory()->create()->id,
        'saldo_inicial' => 500,
    ]);
});

it('accepts an optional cliente on a billetaje solicitado en efectivo', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'efectivo',
        'cliente_id' => $cliente->id,
    ])->assertCreated()->assertJsonPath('data.cliente_id', $cliente->id);
});

it('allows solicitar a billetaje without a cliente for every medio_recepcion', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'yape',
        'datos_recepcion' => '987654321',
    ])->assertCreated()->assertJsonPath('data.cliente_id', null);
});

it('denies a cliente_id that belongs to another agencia', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $clienteAjeno = Cliente::factory()->forAgencia($otraAgencia)->create();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();

    $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'efectivo',
        'cliente_id' => $clienteAjeno->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('cliente_id');
});

it('requires a vaucher to aprobar por cuenta_bancaria, regardless of the medio_recepcion originalmente solicitado', function (string $medioRecepcion) {
    Storage::fake('public');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => $medioRecepcion,
        'datos_recepcion' => $medioRecepcion === 'efectivo' ? null : '987654321',
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar", [
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'transferencia',
        'cuenta_bancaria_id' => $this->cuenta->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('comprobante');
})->with(['efectivo', 'yape']);

it('does not require a vaucher to aprobar en efectivo aunque el asesor haya solicitado yape', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'yape',
        'datos_recepcion' => '987654321',
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    // Default medio_egreso is efectivo — the asesor pidió yape pero el
    // admin terminó entregando cash físico, así que no hay nada que probar.
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")->assertSuccessful();
});

it('lets the asesor see the vaucher image after the billetaje is aprobado por cuenta_bancaria', function () {
    Storage::fake('public');

    $cliente = Cliente::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'yape',
        'datos_recepcion' => '987654321',
        'cliente_id' => $cliente->id,
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->getJson('/api/billetajes')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.cliente.id', $cliente->id)
        ->assertJsonPath('data.data.0.datos_recepcion', '987654321');

    $this->postJson("/api/billetajes/{$billetajeId}/aprobar", [
        'medio_egreso' => 'cuenta_bancaria',
        'canal_egreso' => 'transferencia',
        'cuenta_bancaria_id' => $this->cuenta->id,
        'comprobante' => UploadedFile::fake()->image('vaucher.jpg'),
    ])->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $fotos = $this->getJson('/api/billetajes')->assertSuccessful()->json('data.data.0.fotos');

    expect($fotos)->toHaveCount(1)
        ->and($fotos[0]['tipo'])->toBe('comprobante')
        ->and($fotos[0]['url'])->not->toBeEmpty();
});

it('does not require a vaucher to aprobar a billetaje en efectivo', function () {
    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson('/api/caja/aperturar')->assertCreated();
    $billetajeId = $this->postJson('/api/billetajes', [
        'monto' => 100,
        'motivo' => 'Vuelto para clientes',
        'medio_recepcion' => 'efectivo',
    ])->json('data.id');

    Sanctum::actingAs($this->administradorAgencia, ['*']);
    $this->postJson("/api/billetajes/{$billetajeId}/aprobar")->assertSuccessful();
});
