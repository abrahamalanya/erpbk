<?php

use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\Credito\Services\DocumentoCreditoService;
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
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'hipotecario',
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 30, 'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);
    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');
});

function creditoSinDocumentos(Agencia $agencia, string $tipo, array $atributos = []): Credito
{
    $credito = Credito::factory()->state([
        'empresa_id' => $agencia->empresa_id, 'agencia_id' => $agencia->id, 'tipo_credito' => $tipo,
        'estado' => 'activo', 'fecha_desembolso' => '2026-09-12', ...$atributos,
    ])->create();
    $credito->documentos()->delete();

    return $credito;
}

it('lists the template documentos of an imported crédito that has no rows', function () {
    $credito = creditoSinDocumentos($this->agencia, 'hipotecario');

    Sanctum::actingAs($this->admin, ['*']);

    $tipos = collect($this->getJson("/api/creditos-prendarios/{$credito->id}")->assertOk()->json('data.documentos'))->pluck('tipo')->all();

    expect($tipos)->toBe(['contrato', 'declaracion', 'fotos', 'ficha_socioeconomica', 'notificacion_pago', 'aviso_prejudicial', 'saldo_deudor', 'expediente']);
});

it('does not duplicate template documentos and keeps the event ones', function () {
    $credito = creditoSinDocumentos($this->agencia, 'hipotecario');
    DocumentoCredito::query()->create([
        'credito_id' => $credito->id, 'empresa_id' => $credito->empresa_id, 'tipo' => 'voucher_pago',
        'datos' => ['monto_pagado' => '100.00'], 'generado_por' => $this->admin->id, 'generado_at' => now(),
    ]);

    Sanctum::actingAs($this->admin, ['*']);
    $this->getJson("/api/creditos-prendarios/{$credito->id}")->assertOk();
    $documentos = $this->getJson("/api/creditos-prendarios/{$credito->id}")->assertOk()->json('data.documentos');

    expect(collect($documentos)->countBy('tipo')->max())->toBe(1)
        ->and(collect($documentos)->pluck('tipo'))->toContain('voucher_pago')
        ->and($documentos)->toHaveCount(9);
});

it('gives the recepción de vehículos only to the original vehicular crédito', function () {
    $original = creditoSinDocumentos($this->agencia, 'vehicular');
    $sucesor = creditoSinDocumentos($this->agencia, 'vehicular', ['refrendo_de_credito_id' => $original->id]);

    $servicio = app(DocumentoCreditoService::class);

    expect($servicio->tiposPlantilla($original))->toContain('recepcion_vehiculos')
        ->and($servicio->tiposPlantilla($sucesor))->not->toContain('recepcion_vehiculos')
        ->and($servicio->tiposPlantilla($sucesor))->toContain('sticker');
});

it('gives the diario crédito only the pagaré, and only once disbursed', function () {
    $pendiente = creditoSinDocumentos($this->agencia, 'diario', ['estado' => 'pendiente', 'fecha_desembolso' => null, 'tipo_cuota' => 'diario']);
    $desembolsado = creditoSinDocumentos($this->agencia, 'diario', ['tipo_cuota' => 'diario']);

    $servicio = app(DocumentoCreditoService::class);

    expect($servicio->tiposPlantilla($pendiente))->toBe([])
        ->and($servicio->tiposPlantilla($desembolsado))->toBe(['pagare']);
});
