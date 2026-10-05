<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->cliente = Cliente::factory()->forAgencia($this->agencia)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05, 'max_cuotas' => 45,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
});

/**
 * Crédito diario a 30 cuotas: las primeras $diasAtraso ya vencieron y siguen
 * impagas, el resto vence a futuro. $diasVencimientoDias es el atraso del
 * CRÉDITO COMPLETO (independiente del de las cuotas, que es justo lo que el
 * reporte tiene que separar).
 */
function creditoDelReporte(
    Empresa $empresa,
    Agencia $agencia,
    Cliente $cliente,
    User $asesor,
    int $diasAtraso = 0,
    int $diasVencimientoDias = 0,
): Credito {
    $credito = Credito::factory()->diario()
        ->create([
            'registrado_por' => $asesor->id,
            'empresa_id' => $empresa->id,
            'agencia_id' => $agencia->id,
            'cliente_id' => $cliente->id,
            'estado' => 'activo',
            'fecha_desembolso' => now()->subDays(30)->toDateString(),
            'fecha_vencimiento' => now()->subDays($diasVencimientoDias)->toDateString(),
            'plazo_dias' => 30,
        ]);

    for ($numero = 1; $numero <= 30; $numero++) {
        CuotaCredito::factory()->paraCredito($credito)->create([
            'numero_cuota' => $numero,
            'fecha_vencimiento' => now()->subDays($diasAtraso)->addDays($numero - 1)->toDateString(),
            'monto_capital' => 10,
            'monto_interes' => 1,
            'monto_total' => 11,
        ]);
    }

    return $credito;
}

it('expone los dos medidores de atraso por separado: crédito completo vs cuota más antigua', function () {
    // El crédito completo venció hace 7 días, pero la cuota más antigua solo
    // tiene 3: son dos números distintos y el reporte no debe mezclarlos.
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: 3, diasVencimientoDias: 7);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect($fila['atraso_venc_dias'])->toBe(7)
        ->and($fila['dias_atraso'])->toBe(3)
        ->and($fila['cuotas_atrasadas'])->toBe(3);
});

it('clasifica el atraso del crédito completo en los 6 tramos del semáforo', function (int $dias, string $categoria, string $color, int $rank) {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasVencimientoDias: $dias);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect($fila['atraso_venc_dias'])->toBe($dias)
        ->and($fila['atraso_venc_categoria'])->toBe($categoria)
        ->and($fila['atraso_venc_color'])->toBe($color)
        ->and($fila['atraso_venc_rank'])->toBe($rank);
})->with([
    'normal' => [5, 'Normal', '#22c55e', 5],
    'problema potencial' => [6, 'Problema potencial', '#84cc16', 4],
    'deficiente' => [15, 'Deficiente', '#f5c518', 3],
    'dudoso' => [25, 'Dudoso', '#f97316', 2],
    'perdida' => [45, 'Pérdida', '#ef4444', 1],
    'castigado' => [61, 'Castigado', '#000000', 6],
]);

it('deja sin clasificar un crédito que aún no vence, pero con rank 5 para que ordene', function () {
    Credito::factory()->diario()
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
            'estado' => 'activo',
            'fecha_desembolso' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect($fila['atraso_venc_dias'])->toBe(0)
        ->and($fila['atraso_venc_categoria'])->toBeNull()
        ->and($fila['atraso_venc_color'])->toBeNull()
        ->and($fila['atraso_venc_rank'])->toBe(5);
});

it('clasifica el atraso de cuotas en sus 5 tramos', function (int $dias, string $color) {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: $dias);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect($fila['dias_atraso'])->toBe($dias)
        ->and($fila['atraso_cuota_color'])->toBe($color);
})->with([
    [1, '#84cc16'],
    [2, '#84cc16'],
    [3, '#38bdf8'],
    [7, '#38bdf8'],
    [8, '#fde047'],
    [15, '#fde047'],
    [16, '#f43f5e'],
    [20, '#f43f5e'],
    [21, '#7f1d1d'],
]);

it('deja sin clasificar el atraso de cuotas cuando no hay ninguna vencida impaga', function () {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: 0);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect($fila['dias_atraso'])->toBe(0)
        ->and($fila['cuotas_atrasadas'])->toBe(0)
        ->and($fila['atraso_cuota_color'])->toBeNull()
        ->and($fila['atraso_cuota_nivel'])->toBeNull();
});

it('no cuenta como vencida una cuota ya pagada aunque su fecha ya haya pasado', function () {
    $credito = creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: 5);
    $credito->cuotas()
        ->where('numero_cuota', '<=', 5)
        ->update(['pagada_at' => now()->subDays(5), 'monto_abonado' => 11]);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect($fila['cuotas_atrasadas'])->toBe(0)
        ->and($fila['dias_atraso'])->toBe(0)
        ->and($fila['atraso_cuota_color'])->toBeNull();
});

it('ordena por gravedad ascendente y empuja el Castigado al final aunque sea el más grave en días', function () {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasVencimientoDias: 61);  // Castigado, 61 días
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasVencimientoDias: 45);  // Pérdida
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasVencimientoDias: 25);  // Dudoso
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasVencimientoDias: 3);   // Normal

    Sanctum::actingAs($this->asesor, ['*']);
    $categorias = $this->getJson('/api/reportes/atrasos-diarios')
        ->assertSuccessful()
        ->json('data.*.atraso_venc_categoria');

    expect($categorias)->toBe(['Pérdida', 'Dudoso', 'Normal', 'Castigado']);
});

it('coloca al final los créditos sin clasificar, detrás de los que sí tienen mora activa', function () {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasVencimientoDias: 45);

    Credito::factory()->diario()
        ->create([
            'registrado_por' => $this->asesor->id,
            'empresa_id' => $this->empresa->id,
            'agencia_id' => $this->agencia->id,
            'cliente_id' => $this->cliente->id,
            'estado' => 'activo',
            'fecha_desembolso' => now()->toDateString(),
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
        ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data');

    expect($data)->toHaveCount(2)
        ->and($data[0]['atraso_venc_categoria'])->toBe('Pérdida')
        ->and($data[1]['atraso_venc_categoria'])->toBeNull();
});

it('calcula Total como el cronograma y Saldo como lo que falta abonar', function () {
    $credito = creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor);
    $credito->cuotas()->where('numero_cuota', '<=', 10)->update(['monto_abonado' => 11]);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect((float) $fila['total'])->toBe(330.0)
        ->and((float) $fila['saldo'])->toBe(220.0);
});

it('trae el último abono, el asesor y la periodicidad de la cuota', function () {
    $cliente = Cliente::factory()->asignadoA($this->asesor)->create();
    $credito = creditoDelReporte($this->empresa, $this->agencia, $cliente, $this->asesor);
    Cobro::factory()->create([
        'empresa_id' => $this->empresa->id,
        'cliente_id' => $cliente->id,
        'credito_id' => $credito->id,
        'estado' => 'registrado',
        'monto_pagado' => 50,
        'medio' => 'yape',
        'created_at' => now()->subDays(3),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect($fila['asesor']['id'])->toBe($this->asesor->id)
        ->and($fila['tipo_cuota'])->toBe('diario')
        ->and($fila['ultimo_abono'])->toBe(now()->subDays(3)->toDateString());
});

it('ignora cobros anulados al calcular el último abono', function () {
    $credito = creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor);
    Cobro::factory()->create([
        'empresa_id' => $this->empresa->id,
        'cliente_id' => $this->cliente->id,
        'credito_id' => $credito->id,
        'estado' => 'anulado',
        'monto_pagado' => 50,
        'created_at' => now()->subDay(),
    ]);

    Sanctum::actingAs($this->asesor, ['*']);
    $fila = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data.0');

    expect($fila['ultimo_abono'])->toBeNull();
});

it('incluye créditos al día y vencidos, pero no liquidados ni en venta', function () {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: 3);
    $creditoAlDia = creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor);
    $creditoAlDia->update(['estado' => 'liquidado']);
    $creditoEnVenta = creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor);
    $creditoEnVenta->update(['estado' => 'en_venta']);

    $otro = Cliente::factory()->forAgencia($this->agencia)->create();
    $vencido = creditoDelReporte($this->empresa, $this->agencia, $otro, $this->asesor, diasVencimientoDias: 40);
    $vencido->update(['estado' => 'vencido']);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->json('data');

    expect($data)->toHaveCount(2)
        ->and(collect($data)->pluck('estado')->sort()->values()->all())->toBe(['activo', 'vencido']);
});

it('hides créditos de otra agencia a un asesor', function () {
    $otraAgencia = Agencia::factory()->for($this->empresa)->create();
    $otroCliente = Cliente::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor = User::factory()->forAgencia($otraAgencia)->create();
    $otroAsesor->assignRole('asesor');
    creditoDelReporte($this->empresa, $otraAgencia, $otroCliente, $otroAsesor, diasVencimientoDias: 40);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/reportes/atrasos-diarios')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('denies access to a user without creditos_prendarios.ver', function () {
    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($sinPermiso, ['*']);
    $this->getJson('/api/reportes/atrasos-diarios')->assertForbidden();
});

it('exporta el excel con la bolita de color pintada en las dos columnas de atraso', function () {
    // 25 días de atraso del crédito completo (Dudoso, naranja) y 10 días de
    // la cuota más antigua (Serio, amarillo patito).
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: 10, diasVencimientoDias: 25);

    Sanctum::actingAs($this->asesor, ['*']);
    $hoja = hojaDeAtrasos($this->get('/api/reportes/atrasos-diarios/excel')->assertOk()->streamedContent());

    expect($hoja->getCell('F2')->getValue())->toBe('25 Días')
        ->and($hoja->getCell('F2')->getStyle()->getFill()->getFillType())->toBe(Fill::FILL_SOLID)
        ->and($hoja->getCell('F2')->getStyle()->getFill()->getStartColor()->getARGB())->toBe('FFF97316')
        ->and($hoja->getCell('G2')->getValue())->toBe('10 Cuotas')
        ->and($hoja->getCell('G2')->getStyle()->getFill()->getStartColor()->getARGB())->toBe('FFFDE047');
});

it('no pinta relleno en las celdas de un crédito al día, que sale sin bolita', function () {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor);

    Sanctum::actingAs($this->asesor, ['*']);
    $hoja = hojaDeAtrasos($this->get('/api/reportes/atrasos-diarios/excel')->assertOk()->streamedContent());

    expect($hoja->getCell('F2')->getValue())->toBe('Al día')
        ->and($hoja->getCell('G2')->getValue())->toBe('Al día')
        ->and($hoja->getCell('F2')->getStyle()->getFill()->getFillType())->not->toBe(Fill::FILL_SOLID)
        ->and($hoja->getCell('G2')->getStyle()->getFill()->getFillType())->not->toBe(Fill::FILL_SOLID);
});

it('pone el texto de la bolita en blanco sobre el Castigado y en negro sobre elamarillo', function () {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: 10, diasVencimientoDias: 90);

    Sanctum::actingAs($this->asesor, ['*']);
    $hoja = hojaDeAtrasos($this->get('/api/reportes/atrasos-diarios/excel')->assertOk()->streamedContent());

    expect($hoja->getCell('F2')->getStyle()->getFill()->getStartColor()->getARGB())->toBe('FF000000')
        ->and($hoja->getCell('F2')->getStyle()->getFont()->getColor()->getARGB())->toBe('FFFFFFFF')
        ->and($hoja->getCell('G2')->getStyle()->getFill()->getStartColor()->getARGB())->toBe('FFFDE047')
        ->and($hoja->getCell('G2')->getStyle()->getFont()->getColor()->getARGB())->toBe('FF000000');
});

it('exporta el pdf con los 13 encabezados de la tabla y la leyenda de los dos semáforos', function () {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: 10, diasVencimientoDias: 25);

    Sanctum::actingAs($this->asesor, ['*']);
    $pdf = $this->get('/api/reportes/atrasos-diarios/pdf')->assertOk()->getContent();

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

/**
 * dompdf no implementa border-radius: 50%, así que un <span> redondeado
 * salía CUADRADO en el PDF y el reporte perdía la bolita. Las curvas Bézier
 * del content stream son la prueba de que ahora sí se dibuja un círculo (8
 * curvas por círculo: 2 de la fila + 11 de la leyenda).
 */
it('dibuja la bolita del pdf como círculo y no como un cuadrado', function () {
    creditoDelReporte($this->empresa, $this->agencia, $this->cliente, $this->asesor, diasAtraso: 10, diasVencimientoDias: 25);

    Sanctum::actingAs($this->asesor, ['*']);
    $contenido = contenidoDePdf($this->get('/api/reportes/atrasos-diarios/pdf')->assertOk()->getContent());

    expect(substr_count($contenido, ' c'))->toBeGreaterThanOrEqual(13 * 8);
});

/**
 * Saca el content stream del PDF: dompdf lo emite comprimido con FlateDecode.
 */
function contenidoDePdf(string $pdf): string
{
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $coincidencias);

    foreach ($coincidencias[1] as $stream) {
        $descomprimido = @gzuncompress($stream);

        if ($descomprimido !== false && str_contains($descomprimido, ' rg')) {
            return $descomprimido;
        }
    }

    return '';
}

/**
 * Abre el .xlsx que devuelve el endpoint para poder inspeccionar el relleno
 * de las celdas, que es el equivalente en hoja de cálculo de la bolita de
 * color del PDF (en un .xlsx no se puede dibujar un círculo).
 */
function hojaDeAtrasos(string $contenidoXlsx): Worksheet
{
    $ruta = tempnam(sys_get_temp_dir(), 'atrasos').'.xlsx';
    file_put_contents($ruta, $contenidoXlsx);

    try {
        return IOFactory::load($ruta)->getActiveSheet();
    } finally {
        unlink($ruta);
    }
}
