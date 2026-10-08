<?php

use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Services\BaseImponibleService;

beforeEach(function () {
    $this->servicio = new BaseImponibleService;
    $this->empresa = $this->empresa ?? Empresa::factory()->create();
    $this->configuracion = ConfiguracionFacturacion::factory()->deEmpresa($this->empresa)->create();
});

/**
 * Crea un crédito y sus cobros. Los montos son los mismos que el ejemplo real
 * del cliente: un crédito de 1,000 que se cancela con 1,100 factura 100.
 */
function creditoConCobros(array $cobros, string $estado = 'liquidado'): Credito
{
    $credito = Credito::factory()->create([
        'estado' => $estado,
        'monto_prestamo' => '1000.00',
        'codigo' => 'C-000123',
    ]);

    foreach ($cobros as $atributos) {
        Cobro::factory()->create(array_merge([
            'credito_id' => $credito->id,
            'cliente_id' => $credito->cliente_id,
            'empresa_id' => $credito->empresa_id,
            'estado' => 'registrado',
            'mora' => null,
            'vuelto' => 0,
        ], $atributos));
    }

    return $credito->fresh();
}

it('factura solo el interés del crédito, nunca el capital', function () {
    // Crédito de 1,000 que se cobró completo en una liquidación: 1,100.
    // El capital es 1,000 y el interés 100 — eso es lo único facturable.
    $credito = creditoConCobros([
        [
            'operacion' => 'liquidacion',
            'monto_pagado' => '1100.00',
            'interes' => '100.00',
        ],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    // Con IGV incluido en el interés: 100 / 1.18 = 84.75 de base.
    expect($resultado['base'])->toBe('84.75')
        ->and($resultado['igv'])->toBe('15.26')
        ->and($resultado['total'])->toBe('100.01')
        ->and($resultado['porcentaje_igv'])->toBe('18.00');
});

it('descuenta el capital del total facturado sin importar cuán grande sea el préstamo', function () {
    $credito = creditoConCobros([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    // Si el capital se colara en la base, esto sería >= 1000.
    expect(bccomp($resultado['base'], '1000.00', 2))->toBeLessThan(0)
        ->and(bccomp($resultado['base'], '100.00', 2))->toBeLessThan(0);
});

it('suma el interés de varios cobros y los desglosa en una línea por cobro', function () {
    // Tres refrendos de 30, 40 y 30 soles de interés.
    $credito = creditoConCobros([
        ['operacion' => 'refrendo', 'monto_pagado' => '130.00', 'interes' => '30.00'],
        ['operacion' => 'refrendo', 'monto_pagado' => '140.00', 'interes' => '40.00'],
        ['operacion' => 'refrendo', 'monto_pagado' => '130.00', 'interes' => '30.00'],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    // Cada línea se redondea por separado y el total es la SUMA de las líneas,
    // que es lo que SUNAT valida. Ojo: 100 / 1.18 daría 84.75 redondeando una
    // sola vez, pero redondeando por línea sale 25.42 + 33.90 + 25.42 = 84.74.
    // La diferencia de un céntimo se absorbe con cbc:PayableRoundingAmount en
    // la fase de XML (FACTURACION.md §5.6).
    expect($resultado['lineas'])->toHaveCount(3)
        ->and($resultado['base'])->toBe('84.74')
        ->and($resultado['lineas'][0]['descripcion'])->toContain('crédito C-000123')
        ->and($resultado['lineas'][0]['descripcion'])->toContain('refrendo');
});

it('redondea cada línea antes de sumar, nunca la suma final', function () {
    $credito = creditoConCobros([
        ['operacion' => 'refrendo', 'monto_pagado' => '130.00', 'interes' => '30.00'],
        ['operacion' => 'refrendo', 'monto_pagado' => '140.00', 'interes' => '40.00'],
        ['operacion' => 'refrendo', 'monto_pagado' => '130.00', 'interes' => '30.00'],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    // Si se dividiera el total de una vez, 100 / 1.18 = 84.7457... y el
    // redondeo half-up daría 84.75. Con una línea por cobro da 84.74.
    expect($resultado['base'])->not->toBe('84.75')
        ->and($resultado['base'])->toBe('84.74');

    // Y el total tiene que ser exactamente la suma de las líneas redondeadas.
    $suma = '0.00';
    foreach ($resultado['lineas'] as $linea) {
        $suma = bcadd($suma, $linea['valor_venta'], 2);
    }
    expect($suma)->toBe($resultado['base']);
});

it('emite la mora en una línea separada del interés', function () {
    $credito = creditoConCobros([
        [
            'operacion' => 'liquidacion',
            'monto_pagado' => '115.00',
            'interes' => '100.00',
            'mora' => '15.00',
        ],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    expect($resultado['lineas'])->toHaveCount(2)
        ->and($resultado['lineas'][0]['descripcion'])->toContain('Interés')
        ->and($resultado['lineas'][1]['descripcion'])->toContain('Mora')
        // 115 / 1.18 = 97.4576 -> 97.46
        ->and($resultado['base'])->toBe('97.46');
});

it('ignora los cobros anulados porque ya no generaron ingreso', function () {
    $credito = creditoConCobros([
        ['operacion' => 'refrendo', 'monto_pagado' => '130.00', 'interes' => '30.00'],
        [
            'operacion' => 'refrendo',
            'monto_pagado' => '200.00',
            'interes' => '100.00',
            'estado' => 'anulado',
        ],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    // Solo cuenta el cobro vigente de 30.
    expect($resultado['lineas'])->toHaveCount(1)
        ->and($resultado['base'])->toBe('25.42'); // 30 / 1.18
});

it('rechaza un crédito sin interés cobrado en vez de emitir un comprobante en cero', function () {
    $credito = creditoConCobros([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1000.00', 'interes' => '0.00'],
    ]);

    expect(fn () => $this->servicio->desdeCredito($credito, $this->configuracion))
        ->toThrow(DomainException::class, 'El crédito no tiene interés ni mora cobrados: no hay nada que facturar.');
});

it('suma el IGV encima cuando el interés se cobra sin IGV adentro', function () {
    $this->configuracion->update(['interes_incluye_igv' => false]);

    $credito = creditoConCobros([
        ['operacion' => 'liquidacion', 'monto_pagado' => '100.00', 'interes' => '100.00'],
    ]);

    $resultado = $this->servicio->desdeCredito($credito->fresh(), $this->configuracion);

    // Base = el interés tal cual; el IGV se calcula encima.
    expect($resultado['base'])->toBe('100.00')
        ->and($resultado['igv'])->toBe('18.00')
        ->and($resultado['total'])->toBe('118.00');
});

it('mantiene base + IGV = total al céntimo en cada línea', function () {
    // Es lo que SUNAT valida: el total del documento tiene que ser la suma
    // exacta de las líneas, o rechaza con diferencia de centavos.
    $credito = creditoConCobros([
        ['operacion' => 'refrendo', 'monto_pagado' => '37.77', 'interes' => '33.00'],
        ['operacion' => 'refrendo', 'monto_pagado' => '19.10', 'interes' => '16.66'],
        ['operacion' => 'liquidacion', 'monto_pagado' => '11.86', 'interes' => '10.33'],
        ['operacion' => 'liquidacion', 'monto_pagado' => '12.48', 'interes' => '10.90', 'mora' => '1.58'],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    foreach ($resultado['lineas'] as $linea) {
        expect(bcadd($linea['valor_venta'], $linea['igv'], 2))->toBe($linea['total']);
    }

    $sumaLineas = '0.00';
    $sumaIgv = '0.00';
    foreach ($resultado['lineas'] as $linea) {
        $sumaLineas = bcadd($sumaLineas, $linea['valor_venta'], 2);
        $sumaIgv = bcadd($sumaIgv, $linea['igv'], 2);
    }

    expect($sumaLineas)->toBe($resultado['base'])
        ->and($sumaIgv)->toBe($resultado['igv'])
        ->and(bcadd($resultado['base'], $resultado['igv'], 2))->toBe($resultado['total']);
});

it('devuelve el total en letras porque el XML lo exige', function () {
    $credito = creditoConCobros([
        ['operacion' => 'liquidacion', 'monto_pagado' => '1100.00', 'interes' => '100.00'],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    expect($resultado['total_letras'])->toContain('SOLES');
});

it('conserva el número de IGV sin IGV en el interés entre interés y mora', function () {
    $credito = creditoConCobros([
        ['operacion' => 'liquidacion', 'monto_pagado' => '80.00', 'interes' => '80.00', 'mora' => '10.00'],
    ]);

    $resultado = $this->servicio->desdeCredito($credito, $this->configuracion);

    // (80 + 10) / 1.18 = 76.2711... -> 76.27
    expect($resultado['base'])->toBe('76.27')
        ->and($resultado['lineas'])->toHaveCount(2);
});

it('expone el interés cobrado para contrastarlo contra el cronograma', function () {
    $credito = creditoConCobros([
        ['operacion' => 'refrendo', 'monto_pagado' => '130.00', 'interes' => '30.00'],
        ['operacion' => 'refrendo', 'monto_pagado' => '140.00', 'interes' => '40.00'],
    ]);

    expect($this->servicio->interesCobrado($credito))->toBe('70.00');
});
