<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    Storage::fake('privado');

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    Storage::disk('privado')->put('sunat/empresa.pfx', file_get_contents(base_path('tests/Fixtures/Facturacion/certificado_moderno.pfx')));
    CredencialSunat::factory()->deEmpresa($this->empresa)->create([
        'certificado_path' => 'sunat/empresa.pfx',
        'certificado_password' => 'testpass123',
    ]);

    ConfiguracionFacturacion::factory()->deEmpresa($this->empresa)->activa()->create();

    SerieComprobante::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => null,
        'tipo_documento' => TipoComprobante::NotaCredito->value, 'serie' => 'BC01', 'estado' => true,
    ]);

    $this->comprobante = Comprobante::factory()->create([
        'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
        'estado' => EstadoComprobante::Aceptado->value,
    ]);

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
    Sanctum::actingAs($this->admin, ['*']);
});

it('calcula el subtotal de la nota de crédito como total menos igv de cada línea, sin depender de valor_venta', function () {
    // Antes: `(string) $linea['valor_venta'] ?? $linea['valor_unitario']` nunca
    // usaba valor_unitario de verdad (el cast a string convierte el null del
    // índice inexistente en '', que no es null) — el subtotal quedaba mal
    // apenas la línea no traía 'valor_venta', que es siempre, porque
    // EmitirNotaCreditoRequest ni lo valida ni el frontend lo manda.
    $response = $this->postJson("/api/facturacion/comprobantes/{$this->comprobante->id}/nota-credito", [
        'motivo' => 'Devolución parcial',
        'lineas' => [
            ['descripcion' => 'Ajuste de interés', 'cantidad' => 1, 'valor_unitario' => 10.00, 'igv' => 1.80, 'total' => 11.80],
            ['descripcion' => 'Ajuste de mora', 'cantidad' => 1, 'valor_unitario' => 5.00, 'igv' => 0.90, 'total' => 5.90],
        ],
    ])->assertCreated();

    $nota = Comprobante::query()->findOrFail($response->json('data.id'));

    expect($nota->subtotal)->toBe('15.00')
        ->and($nota->igv)->toBe('2.70')
        ->and($nota->total)->toBe('17.70')
        ->and($nota->tipo_documento)->toBe(TipoComprobante::NotaCredito)
        ->and($nota->documento_origen_id)->toBe($this->comprobante->id)
        ->and($response->json('data.documento_origen'))->toBe($this->comprobante->numeroFormateado());
});

it('rechaza una nota de crédito sobre un comprobante que no está aceptado ni observado', function () {
    $this->comprobante->update(['estado' => EstadoComprobante::Generado->value]);

    $this->postJson("/api/facturacion/comprobantes/{$this->comprobante->id}/nota-credito", [
        'motivo' => 'Devolución',
        'lineas' => [['descripcion' => 'x', 'cantidad' => 1, 'valor_unitario' => 10, 'igv' => 1.80, 'total' => 11.80]],
    ])->assertUnprocessable();
});
