<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Facturacion\Services\SerieService;
use App\Modules\Facturacion\Tipos\TipoComprobante;

beforeEach(function () {
    $this->servicio = new SerieService;
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
});

it('registra una serie normalizada en mayúsculas', function () {
    $serie = $this->servicio->registrar($this->empresa->id, $this->agencia->id, TipoComprobante::Boleta, ' b001 ');

    expect($serie->serie)->toBe('B001')
        ->and($serie->tipo_documento)->toBe('03')
        ->and($serie->ultimo_correlativo)->toBe(0)
        ->and($serie->agencia_id)->toBe($this->agencia->id)
        ->and($serie->estado)->toBeTrue();
});

it('rechaza una serie que no tiene 4 caracteres', function () {
    expect(fn () => $this->servicio->registrar($this->empresa->id, null, TipoComprobante::Boleta, 'B01'))
        ->toThrow(DomainException::class, 'La serie debe tener exactamente 4 caracteres alfanuméricos.');

    expect(fn () => $this->servicio->registrar($this->empresa->id, null, TipoComprobante::Boleta, 'B0001'))
        ->toThrow(DomainException::class);
});

it('no permite registrar dos veces la misma serie en la misma empresa', function () {
    SerieComprobante::factory()->deEmpresa($this->empresa)->boleta('B001')->create();

    expect(fn () => $this->servicio->registrar($this->empresa->id, $this->agencia->id, TipoComprobante::Boleta, 'B001'))
        ->toThrow(DomainException::class, 'La serie B001 ya está registrada en esta empresa.');
});

it('entrega correlativos consecutivos empezando en 1', function () {
    $serie = SerieComprobante::factory()->deEmpresa($this->empresa)->boleta()->create();

    expect($this->servicio->siguienteCorrelativo($serie))->toBe(1)
        ->and($this->servicio->siguienteCorrelativo($serie))->toBe(2)
        ->and($this->servicio->siguienteCorrelativo($serie))->toBe(3)
        ->and($serie->fresh()->ultimo_correlativo)->toBe(3);
});

it('continúa la numeración desde el correlativo ya emitido', function () {
    $serie = SerieComprobante::factory()->deEmpresa($this->empresa)->boleta()->conCorrelativo(1523)->create();

    expect($this->servicio->siguienteCorrelativo($serie))->toBe(1524);
});

it('nunca libera un correlativo aunque el envío a SUNAT falle', function () {
    // Esta es la garantía central del módulo: si el envío falla, el
    // comprobante conserva su número y el reintento usa el MISMO. Un hueco en
    // la numeración invalida la operación ante SUNAT.
    $serie = SerieComprobante::factory()->deEmpresa($this->empresa)->boleta()->create();

    $primero = $this->servicio->siguienteCorrelativo($serie);

    // "Falla el envío": no se devuelve nada al contador, no hay método para eso.
    $reintento = $this->servicio->siguienteCorrelativo($serie->fresh());

    expect($primero)->toBe(1)
        ->and($reintento)->toBe(2)
        ->and($reintento)->not->toBe($primero);
});

it('bloquea la emisión cuando la serie agotó el rango autorizado por SUNAT', function () {
    $serie = SerieComprobante::factory()->deEmpresa($this->empresa)->boleta()->sinCupo()->create();

    expect($serie->tieneCupo())->toBeFalse()
        ->and(fn () => $this->servicio->siguienteCorrelativo($serie))
        ->toThrow(DomainException::class, 'La serie B001 alcanzó el límite de numeración autorizado por SUNAT. Debe solicitar una serie nueva.');
});

it('permite emitir mientras haya cupo y lo bloquea en el último número', function () {
    $serie = SerieComprobante::factory()->deEmpresa($this->empresa)->boleta()->create([
        'ultimo_correlativo' => 99,
        'limite_numeracion' => 100,
    ]);

    // El correlativo 100 sí sale: el límite es el último número autorizado.
    expect($this->servicio->siguienteCorrelativo($serie))->toBe(100);

    expect(fn () => $this->servicio->siguienteCorrelativo($serie->fresh()))
        ->toThrow(DomainException::class);
});

it('resuelve la serie del punto de emisión antes que la de casa matriz', function () {
    $casaMatriz = SerieComprobante::factory()->deEmpresa($this->empresa)->boleta('B001')->create();
    $deAgencia = SerieComprobante::factory()->deAgencia($this->agencia)->boleta('B002')->create();

    expect($this->servicio->serieParaEmitir($this->empresa->id, $this->agencia->id, TipoComprobante::Boleta)->id)
        ->toBe($deAgencia->id);

    // Sin agencia (casa matriz) cae en la serie general.
    expect($this->servicio->serieParaEmitir($this->empresa->id, null, TipoComprobante::Boleta)->id)
        ->toBe($casaMatriz->id);
});

it('cae en la serie de casa matriz si la agencia no tiene serie propia', function () {
    $casaMatriz = SerieComprobante::factory()->deEmpresa($this->empresa)->factura('F001')->create();

    expect($this->servicio->serieParaEmitir($this->empresa->id, $this->agencia->id, TipoComprobante::Factura)->id)
        ->toBe($casaMatriz->id);
});

it('no usa una serie inactiva para emitir', function () {
    SerieComprobante::factory()->deAgencia($this->agencia)->boleta('B002')->inactiva()->create();

    expect(fn () => $this->servicio->serieParaEmitir($this->empresa->id, $this->agencia->id, TipoComprobante::Boleta))
        ->toThrow(DomainException::class, 'No hay ninguna serie activa de Boleta de Venta Electrónica en este punto de emisión. Debe registrar una antes de emitir.');
});

it('no mezcla las series de factura con las de boleta', function () {
    SerieComprobante::factory()->deEmpresa($this->empresa)->factura('F001')->create();

    // Hay serie de factura pero se pide una boleta: debe fallar, no reuse F001.
    expect(fn () => $this->servicio->serieParaEmitir($this->empresa->id, null, TipoComprobante::Boleta))
        ->toThrow(DomainException::class);
});

it('no ve series de otra empresa', function () {
    $otra = Empresa::factory()->create();
    SerieComprobante::factory()->deEmpresa($otra)->boleta('B001')->create();

    expect(fn () => $this->servicio->serieParaEmitir($this->empresa->id, null, TipoComprobante::Boleta))
        ->toThrow(DomainException::class);
});

it('formatea el número como lo exige SUNAT', function () {
    expect($this->servicio->formatearNumero('F001', 123))->toBe('F001-00000123')
        ->and($this->servicio->formatearNumero('B001', 1))->toBe('B001-00000001')
        ->and($this->servicio->formatearNumero('BC01', 7))->toBe('BC01-00000007');
});

it('arma el nombre del archivo XML con el formato de SUNAT', function () {
    expect($this->servicio->nombreArchivo('20123456789', TipoComprobante::Factura, 'F001', 123))
        ->toBe('20123456789-01-F001-00000123.xml');

    expect($this->servicio->nombreArchivo('20123456789', TipoComprobante::Boleta, 'B001', 1))
        ->toBe('20123456789-03-B001-00000001.xml');
});
