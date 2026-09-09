<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'interes_default' => 10, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 1,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    CajaCiclo::query()->create([
        'caja_id' => $caja->id, 'empresa_id' => $caja->empresa_id, 'fecha' => now()->toDateString(),
        'estado' => 'abierta', 'saldo_apertura' => 10000, 'abierta_at' => now(),
    ]);
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    $this->bien = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'nombre' => 'Cocina', 'valorizacion' => 1000]);

    $this->adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $this->adminAgencia->assignRole('administrador_agencia');
});

function registrarCreditoBasico($test, float $monto = 500): int
{
    Sanctum::actingAs($test->asesor, ['*']);

    return $test->postJson('/api/creditos-prendarios', [
        'bien_ids' => [$test->bien->id],
        'monto_prestamo' => $monto, 'tipo_cuota' => 'mensual',
    ])->assertCreated()->json('data.id');
}

it('assigns a unique legible código to a garantía on creation', function () {
    expect($this->bien->fresh()->codigo)->toBe('B-'.str_pad((string) $this->bien->id, 6, '0', STR_PAD_LEFT));

    $otro = Bien::factory()->paraCliente($this->cliente)->create(['tipo' => 'electro', 'valorizacion' => 500]);
    expect($otro->codigo)->not->toBe($this->bien->fresh()->codigo);
});

it('previews a projected cronograma from raw form values without persisting anything', function () {
    Sanctum::actingAs($this->asesor, ['*']);

    $response = $this->postJson('/api/creditos-prendarios/cronograma-preview', [
        'monto_prestamo' => 400, 'interes' => 10, 'tipo_cuota' => 'semanal',
    ])->assertOk();

    expect($response->json('data.cuotas'))->toHaveCount(4)
        ->and($response->json('data.plazo_dias'))->toBe(28)
        ->and($response->json('data.fecha_base'))->toBe(now()->toDateString())
        ->and($response->json('data.cuotas.0.fecha_vencimiento'))->toBe(now()->startOfDay()->addDays(7)->toDateString());

    $sumaCapital = collect($response->json('data.cuotas'))->sum(fn ($c) => (float) $c['monto_capital']);
    expect(number_format($sumaCapital, 2, '.', ''))->toBe('400.00');

    expect(Credito::count())->toBe(0);
});

it('generates the sticker documento as soon as the crédito is registered', function () {
    $creditoId = registrarCreditoBasico($this);

    $sticker = Credito::find($creditoId)->documentos()->where('tipo', 'sticker')->first();
    expect($sticker)->not->toBeNull();

    $pdf = $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$sticker->id}/ver")->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

it('prints the garantía código on the sticker label', function () {
    $creditoId = registrarCreditoBasico($this);
    $credito = Credito::find($creditoId)->load(['cliente', 'agencia', 'empresa']);

    $html = view('modules.credito-prendario.documentos.sticker', [
        'credito' => $credito,
        'garantias' => $credito->bienes,
        'datos' => [],
        'fotoDataUri' => fn (): ?string => null,
    ])->render();

    expect($this->bien->fresh()->codigo)->toStartWith('B-')
        ->and($html)->toContain('digo del producto')
        ->and($html)->toContain($this->bien->fresh()->codigo);
});

it('generates a voucher de desembolso with a snapshot when the crédito is disbursed', function () {
    Storage::fake('public');

    $creditoId = registrarCreditoBasico($this);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $voucher = Credito::find($creditoId)->documentos()->where('tipo', 'voucher_desembolso')->firstOrFail();
    expect($voucher->datos['monto'])->toBe('500.00')
        ->and($voucher->datos['medio'])->toBe('efectivo')
        ->and($voucher->datos['fecha_desembolso'])->toBe(now()->toDateString());

    $pdf = $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$voucher->id}/ver")->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

it('generates a detailed voucher de pago on liquidación', function () {
    Storage::fake('public');

    $creditoId = registrarCreditoBasico($this, 1000);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $total = $this->getJson("/api/creditos-prendarios/{$creditoId}")->json('data.monto_liquidacion_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$creditoId}/liquidar", ['monto_pagado' => $total, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $voucher = Credito::find($creditoId)->documentos()->where('tipo', 'voucher_pago')->firstOrFail();
    expect($voucher->datos['operacion'])->toBe('liquidacion')
        ->and($voucher->datos['capital'])->toBe('1000.00')
        ->and($voucher->datos['monto_pagado'])->toBe((string) $total);

    $pdf = $this->get("/api/creditos-prendarios/{$creditoId}/documentos/{$voucher->id}/ver")->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf');
});

it('generates a detailed voucher de pago on refrendo, attached to the original crédito', function () {
    Storage::fake('public');

    $creditoId = registrarCreditoBasico($this, 1000);

    Sanctum::actingAs($this->adminAgencia, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/aprobar")->assertSuccessful();

    Sanctum::actingAs($this->asesor, ['*']);
    $this->postJson("/api/creditos-prendarios/{$creditoId}/desembolsar")->assertSuccessful();

    $interes = $this->getJson("/api/creditos-prendarios/{$creditoId}")->json('data.monto_refrendo_sugerido.total');
    $this->postJson("/api/creditos-prendarios/{$creditoId}/refrendar", ['monto_pagado' => $interes, 'medio' => 'efectivo'])
        ->assertSuccessful();

    $voucher = Credito::find($creditoId)->documentos()->where('tipo', 'voucher_pago')->firstOrFail();
    expect($voucher->datos['operacion'])->toBe('refrendo')
        ->and($voucher->datos['interes'])->toBe((string) $interes)
        ->and($voucher->datos['credito_sucesor_id'])->not->toBeNull();
});
