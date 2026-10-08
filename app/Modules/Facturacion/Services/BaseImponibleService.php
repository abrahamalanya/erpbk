<?php

namespace App\Modules\Facturacion\Services;

use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Nucleo\Support\NumeroALetras;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Calcula la base imponible de un comprobante a partir del crédito que lo
 * origina.
 *
 * ## La regla
 *
 * Se factura **solo el interés, la mora y los conceptos cobrados**. El capital
 * NUNCA entra: es devolución del principal, no ingreso de la financiera. Un
 * crédito de 1,000 cancelado con 1,100 factura 100, no 1,100.
 *
 * ## La fuente
 *
 * Los cobros registrados en `cobros`, no el cronograma. El cronograma dice lo
 * devengado (lo que el crédito *debía* cobrar); los cobros dicen lo cobrado.
 * Facturar sobre lo cobrado es lo que cuadra con el Libro de Ventas y con el
 * ingreso real en caja.
 *
 * ## Precisión
 *
 * Todo es bcmath con escala 2, igual que CreditoService y VentaService. Con
 * float, 0.1 + 0.2 da 0.30000000000000004 y SUNAT rechaza por diferencia de
 * centavos.
 *
 * ## Redondeo
 *
 * El IGV de cada línea es `base × porcentaje`, nunca `total − base`: SUNAT
 * valida que el impuesto guardado corresponda a la tasa declarada. La
 * consecuencia es que la suma de las líneas puede diferir en centavos del monto
 * realmente cobrado. Esa diferencia se resuelve en la fase de XML con
 * `cbc:PayableRoundingAmount`, que existe justamente para esto (ver
 * FACTURACION.md §5.6).
 */
final class BaseImponibleService
{
    /**
     * @return array{
     *     lineas: list<array<string, mixed>>,
     *     base: string,
     *     igv: string,
     *     total: string,
     *     porcentaje_igv: string,
     *     total_letras: string
     * }
     */
    public function desdeCredito(Credito $credito, ConfiguracionFacturacion $configuracion): array
    {
        $porcentaje = $this->porcentajeIgv($configuracion);
        $divisor = $this->divisorDeDesagregacion($configuracion, $porcentaje);

        $lineas = [];
        $base = '0.00';
        $igv = '0.00';

        /** @var Cobro $cobro */
        foreach ($this->cobrosDelCredito($credito) as $cobro) {
            foreach ($this->conceptosDelCobro($cobro, $credito) as $concepto) {
                // Si el interés ya trae el IGV adentro, primero se separa la
                // base; si no, el monto ES la base y el IGV se suma encima.
                $valorVenta = $this->aEscala($this->dividir($concepto['monto'], $divisor), 2);
                $igvLinea = $this->aEscala($this->igvDe($valorVenta, $porcentaje), 2);

                $lineas[] = [
                    'orden' => count($lineas) + 1,
                    'descripcion' => $concepto['descripcion'],
                    'unidad_medida' => 'ZZ',
                    'cantidad' => '1.0000',
                    'valor_unitario' => $valorVenta,
                    'precio_unitario' => bcadd($valorVenta, $igvLinea, 2),
                    'descuento' => '0.00',
                    'valor_venta' => $valorVenta,
                    'igv' => $igvLinea,
                    'total' => bcadd($valorVenta, $igvLinea, 2),
                    'categoria_tributo' => 'S',
                    'porcentaje_igv' => $porcentaje,
                    'cobro_id' => $cobro->id,
                ];

                $base = bcadd($base, $valorVenta, 2);
                $igv = bcadd($igv, $igvLinea, 2);
            }
        }

        if (bccomp($base, '0', 2) <= 0) {
            throw new DomainException(
                'El crédito no tiene interés ni mora cobrados: no hay nada que facturar.'
            );
        }

        $total = bcadd($base, $igv, 2);

        return [
            'lineas' => $lineas,
            'base' => $base,
            'igv' => $igv,
            'total' => $total,
            'porcentaje_igv' => $porcentaje,
            'total_letras' => $this->enLetras($total),
        ];
    }

    /**
     * Suma del interés efectivamente cobrado, para verificar contra el
     * cronograma antes de emitir (FACTURACION.md §3.1).
     */
    public function interesCobrado(Credito $credito): string
    {
        $total = '0.00';

        /** @var Cobro $cobro */
        foreach ($this->cobrosDelCredito($credito) as $cobro) {
            foreach ($this->conceptosDelCobro($cobro, $credito) as $concepto) {
                $total = bcadd($total, $concepto['monto'], 2);
            }
        }

        return $total;
    }

    /**
     * Solo los cobros vigentes: un cobro anulado (CreditoService::anularCobro)
     * ya no generó ingreso, así que no se factura.
     *
     * @return Collection<int, Cobro>
     */
    private function cobrosDelCredito(Credito $credito): Collection
    {
        return Cobro::query()
            ->where('credito_id', $credito->id)
            ->where('estado', 'registrado')
            ->orderBy('id')
            ->get();
    }

    /**
     * Descompone un cobro en las líneas del comprobante. El interés siempre
     * genera línea; la mora solo si se cobró algo (y va en línea aparte porque
     * SUNAT la trata como concepto distinto).
     *
     * @return list<array{descripcion: string, monto: string}>
     */
    private function conceptosDelCobro(Cobro $cobro, Credito $credito): array
    {
        $referencia = 'crédito '.$credito->codigo;
        $conceptos = [];

        $interes = $this->aEscala($cobro->interes, 2);

        if (bccomp($interes, '0', 2) > 0) {
            $conceptos[] = [
                'descripcion' => $this->describir($cobro->operacion, 'Interés', $referencia),
                'monto' => $interes,
            ];
        }

        $mora = $this->aEscala((string) ($cobro->mora ?? '0'), 2);

        if (bccomp($mora, '0', 2) > 0) {
            $conceptos[] = [
                'descripcion' => $this->describir($cobro->operacion, 'Mora', $referencia),
                'monto' => $mora,
            ];
        }

        return $conceptos;
    }

    /**
     * Descripción de la línea tal como se imprime en el comprobante. El texto
     * va al PDF y al XML, así que tiene que ser legible para el cliente final.
     */
    private function describir(string $operacion, string $concepto, string $referencia): string
    {
        $sufijo = match ($operacion) {
            'refrendo' => 'por refrendo',
            'adenda' => 'por adenda',
            'liquidacion' => 'por liquidación',
            'refinanciamiento' => 'por refinanciamiento',
            'pago_cuota', 'pago_cuotas_diario' => 'por pago de cuota',
            default => '',
        };

        $descripcion = "{$concepto} {$referencia}";

        return $sufijo === '' ? $descripcion : "{$descripcion} {$sufijo}";
    }

    private function porcentajeIgv(ConfiguracionFacturacion $configuracion): string
    {
        $porcentaje = $this->aEscala($configuracion->igv_porcentaje, 2);

        return bccomp($porcentaje, '0', 2) <= 0 ? '18.00' : $porcentaje;
    }

    /**
     * Por qué se divide: si el interés ya incluye IGV, el monto cobrado es
     * `base × (1 + tasa)` y la base es el monto / (1 + tasa). Si NO incluye,
     * el monto cobrado ES la base y no se divide (el IGV se suma después).
     */
    private function divisorDeDesagregacion(ConfiguracionFacturacion $configuracion, string $porcentaje): string
    {
        if ($configuracion->interes_incluye_igv) {
            return bcadd('1', bcdiv($porcentaje, '100', 10), 10);
        }

        return '1';
    }

    /**
     * `importeTotalEnLetras` es obligatorio en el XML. El servicio ya existe
     * en el núcleo y está en uso en los vouchers.
     */
    private function enLetras(string $monto): string
    {
        return NumeroALetras::soles($monto);
    }

    /**
     * IGV de una línea: base × (porcentaje / 100). El porcentaje viene como
     * "18.00", así que hay que convertirlo a fracción antes de multiplicar o
     * el resultado sale cien veces mayor.
     */
    private function igvDe(string $base, string $porcentaje): string
    {
        return bcmul($base, bcdiv($porcentaje, '100', 10), 10);
    }

    private function dividir(string $dividendo, string $divisor): string
    {
        return bccomp($divisor, '0', 10) === 0 ? $dividendo : bcdiv($dividendo, $divisor, 10);
    }

    /**
     * Redondeo half-up a la escala pedida.
     *
     * El truco es sumar media unidad de la última cifra y dejar que bcmath
     * resuelva: sumar 0.005 y escalar a 2 decimales equivale a redondear a dos
     * decimales. Ojo con el conteo de ceros — media unidad a escala 2 es
     * 0.005 (dos ceros antes del 5), no 0.05.
     *
     * Es el mismo patrón de bcRoundEntero() en CreditoService.
     */
    private function redondear(string $numero, int $escala): string
    {
        $mitad = $escala === 0
            ? '0.5'
            : '0.'.str_repeat('0', $escala).'5';

        return bcadd($numero, $mitad, $escala);
    }

    /**
     * Normaliza a la escala pedida como string, redondeando. Los casts
     * `decimal:2` de Eloquent ya devuelven string, pero un atributo sin cast
     * (mora nullable) puede venir como null o float. Importante: NO usar
     * `bcadd($valor, '0', $escala)` para esto, porque bcmath TRUNCA al reducir
     * la escala y 67.7966 se volvería 67.79 en vez de 67.80.
     */
    private function aEscala(mixed $valor, int $escala): string
    {
        if ($valor === null || $valor === '') {
            return $escala === 0 ? '0' : '0.'.str_repeat('0', $escala);
        }

        return $this->redondear((string) $valor, $escala);
    }
}
