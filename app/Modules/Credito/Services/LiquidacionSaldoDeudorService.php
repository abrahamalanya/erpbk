<?php

namespace App\Modules\Credito\Services;

use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Liquidación del saldo deudor de un crédito hipotecario con las tasas
 * legales (tope BCRP para el compensatorio, Art. 1246 C.C. para el
 * moratorio) en vez de las pactadas.
 *
 * Reglas:
 *
 * - La deuda se mira como una sola desde el crédito original: refrendos,
 *   adendas, refinanciamientos y pagos de cuota crean créditos sucesores,
 *   pero ninguno entrega dinero nuevo, así que el capital es el desembolsado
 *   por el primero y los pagos son los de toda la cadena.
 * - Compensatorio: el capital vivo devenga cada día la tasa diaria
 *   equivalente a la TEA, (1 + TEA)^(1/360) − 1, sin capitalizar intereses
 *   (no hay anatocismo), así que partir el periodo en tramos no cambia el
 *   total.
 * - Moratorio: solo sobre el capital vencido e impago según el cronograma
 *   original, desde el vencimiento de cada cuota, con la tasa diaria
 *   equivalente a la tasa moratoria anual.
 * - Cada pago se imputa primero al moratorio, luego al compensatorio y el
 *   resto al capital (Art. 1257 C.C.).
 * - Se cuenta lo efectivamente recibido (monto pagado − vuelto) de los cobros
 *   no anulados; los descuentos que se concedieron sobre el interés pactado
 *   no aplican porque el interés se recalcula entero.
 */
final class LiquidacionSaldoDeudorService
{
    private const ESCALA = 10;

    public function __construct(private readonly ConfiguracionCreditoService $configuracion) {}

    /**
     * @return array{proyectado: bool, fecha_corte: string, fecha_origen: string, capital_inicial: string, numero_cuotas: int, tipo_cuota: string|null, vencimiento_programado: string|null, tea_compensatoria: string, tasa_moratoria: string, dias: int, interes_compensatorio: string, interes_moratorio: string, total_pagos: string, pagos: list<string>, saldo: string, tramos: list<array{desde: string, hasta: string, dias: int, compensatorio: string, moratorio: string, pago: string, aplicado_intereses: string, aplicado_capital: string, capital_vivo: string, ultimo: bool}>}
     */
    public function liquidar(Credito $credito, CarbonInterface $fechaCorte): array
    {
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);

        if ($configuracion->tea_compensatoria_maxima === null || $configuracion->tasa_moratoria_anual === null) {
            throw new DomainException('Registra la TEA compensatoria máxima y la tasa moratoria anual en la configuración de créditos hipotecarios para emitir la liquidación de saldo deudor.');
        }

        $cadena = $credito->cadena();
        $original = $cadena->first();

        // Antes del desembolso no hay deuda que liquidar: se proyecta con
        // origen en la fecha de corte (el capital, sin intereses), igual que
        // el expediente muestra el cronograma tentativo.
        $proyectado = $original->fecha_desembolso === null;
        $corte = CarbonImmutable::parse($fechaCorte)->startOfDay();
        $origen = $proyectado ? $corte : CarbonImmutable::parse($original->fecha_desembolso)->startOfDay();
        $vencimientos = $this->vencimientosDeCapital($original);
        $pagos = $this->pagosPorFecha($cadena, $origen, $corte);

        $tasaCompensatoria = $this->tasaDiaria((string) $configuracion->tea_compensatoria_maxima);
        $tasaMoratoria = $this->tasaDiaria((string) $configuracion->tasa_moratoria_anual);

        $capitalVivo = (string) $original->monto_prestamo;
        $capitalAmortizado = '0';
        $compensatorioPendiente = '0';
        $moratorioPendiente = '0';
        $tramos = [];
        $totalCompensatorio = '0';
        $totalMoratorio = '0';

        // Los tramos se cierran en cada fecha de pago y en la fecha de corte;
        // los vencimientos de cuota solo parten el cálculo del moratorio.
        $cierres = $pagos->keys()->push($corte->toDateString())->unique()->sort()->values();
        $inicioTramo = $origen;

        foreach ($cierres as $cierre) {
            $finTramo = CarbonImmutable::parse($cierre);
            $compensatorio = '0';
            $moratorio = '0';

            foreach ($this->segmentos($inicioTramo, $finTramo, $vencimientos) as [$desde, $dias]) {
                $base = bccomp($capitalVivo, '0', 2) > 0 ? $capitalVivo : '0';
                $vencido = bcsub($this->capitalExigible($vencimientos, $desde), $capitalAmortizado, self::ESCALA);
                $vencido = bccomp($vencido, '0', self::ESCALA) > 0 ? $vencido : '0';

                $compensatorio = bcadd($compensatorio, bcmul(bcmul($base, $tasaCompensatoria, self::ESCALA), (string) $dias, self::ESCALA), self::ESCALA);
                $moratorio = bcadd($moratorio, bcmul(bcmul($vencido, $tasaMoratoria, self::ESCALA), (string) $dias, self::ESCALA), self::ESCALA);
            }

            $compensatorio = $this->redondear($compensatorio);
            $moratorio = $this->redondear($moratorio);
            $compensatorioPendiente = bcadd($compensatorioPendiente, $compensatorio, 2);
            $moratorioPendiente = bcadd($moratorioPendiente, $moratorio, 2);
            $totalCompensatorio = bcadd($totalCompensatorio, $compensatorio, 2);
            $totalMoratorio = bcadd($totalMoratorio, $moratorio, 2);

            $pago = $pagos->get($cierre, '0.00');
            $aMoratorio = $this->menor($pago, $moratorioPendiente);
            $aCompensatorio = $this->menor(bcsub($pago, $aMoratorio, 2), $compensatorioPendiente);
            $aCapital = bcsub($pago, bcadd($aMoratorio, $aCompensatorio, 2), 2);

            $moratorioPendiente = bcsub($moratorioPendiente, $aMoratorio, 2);
            $compensatorioPendiente = bcsub($compensatorioPendiente, $aCompensatorio, 2);
            $capitalVivo = bcsub($capitalVivo, $aCapital, 2);
            $capitalAmortizado = bcadd($capitalAmortizado, $aCapital, 2);

            $tramos[] = [
                'desde' => $inicioTramo->toDateString(),
                'hasta' => $finTramo->toDateString(),
                'dias' => (int) $inicioTramo->diffInDays($finTramo),
                'compensatorio' => $compensatorio,
                'moratorio' => $moratorio,
                'pago' => bcadd($pago, '0', 2),
                'aplicado_intereses' => bcadd($aMoratorio, $aCompensatorio, 2),
                'aplicado_capital' => $aCapital,
                'capital_vivo' => $capitalVivo,
                'ultimo' => false,
            ];

            $inicioTramo = $finTramo;
        }

        $tramos[array_key_last($tramos)]['ultimo'] = true;
        $totalPagos = $pagos->reduce(fn (string $total, string $monto): string => bcadd($total, $monto, 2), '0.00');

        return [
            'proyectado' => $proyectado,
            'fecha_corte' => $corte->toDateString(),
            'fecha_origen' => $origen->toDateString(),
            'capital_inicial' => bcadd((string) $original->monto_prestamo, '0', 2),
            'numero_cuotas' => (int) $original->numero_cuotas,
            'tipo_cuota' => $original->tipo_cuota,
            'vencimiento_programado' => $vencimientos->last()['fecha'] ?? null,
            'tea_compensatoria' => bcadd((string) $configuracion->tea_compensatoria_maxima, '0', 2),
            'tasa_moratoria' => bcadd((string) $configuracion->tasa_moratoria_anual, '0', 2),
            'dias' => (int) $origen->diffInDays($corte),
            'interes_compensatorio' => $totalCompensatorio,
            'interes_moratorio' => $totalMoratorio,
            'total_pagos' => $totalPagos,
            'pagos' => $pagos->values()->all(),
            'saldo' => bcsub(bcadd(bcadd((string) $original->monto_prestamo, $totalCompensatorio, 2), $totalMoratorio, 2), $totalPagos, 2),
            'tramos' => $tramos,
        ];
    }

    /**
     * Capital que el cronograma original hacía exigible en cada fecha. Un
     * crédito sin cronograma (interés simple) vence entero en su fecha de
     * vencimiento.
     *
     * @return Collection<int, array{fecha: string, capital: string}>
     */
    private function vencimientosDeCapital(Credito $original): Collection
    {
        $cuotas = $original->cuotas()->get();

        if ($cuotas->isEmpty()) {
            return $original->fecha_vencimiento
                ? collect([['fecha' => $original->fecha_vencimiento->toDateString(), 'capital' => (string) $original->monto_prestamo]])
                : collect();
        }

        return $cuotas
            ->filter(fn (CuotaCredito $cuota): bool => $cuota->fecha_vencimiento !== null)
            ->sortBy('fecha_vencimiento')
            ->map(fn (CuotaCredito $cuota): array => ['fecha' => $cuota->fecha_vencimiento->toDateString(), 'capital' => (string) $cuota->monto_capital])
            ->values();
    }

    /**
     * Lo recibido de los cobros no anulados de la cadena, sumado por día.
     *
     * @param  Collection<int, Credito>  $cadena
     * @return Collection<string, string>
     */
    private function pagosPorFecha(Collection $cadena, CarbonImmutable $origen, CarbonImmutable $corte): Collection
    {
        return Cobro::query()
            ->whereIn('credito_id', $cadena->pluck('id'))
            ->where('estado', 'registrado')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Cobro $cobro): bool => $cobro->created_at->copy()->startOfDay()->lte($corte))
            ->groupBy(fn (Cobro $cobro): string => max($cobro->created_at->toDateString(), $origen->toDateString()))
            ->map(fn (Collection $cobros): string => $cobros->reduce(
                fn (string $total, Cobro $cobro): string => bcadd($total, bcsub((string) $cobro->monto_pagado, (string) ($cobro->vuelto ?? '0'), 2), 2),
                '0.00',
            ))
            ->filter(fn (string $monto): bool => bccomp($monto, '0', 2) > 0)
            ->sortKeys();
    }

    /**
     * Parte [desde, hasta) en los vencimientos de cuota que caen dentro, para
     * que el capital vencido sea constante en cada segmento.
     *
     * @param  Collection<int, array{fecha: string, capital: string}>  $vencimientos
     * @return list<array{0: CarbonImmutable, 1: int}>
     */
    private function segmentos(CarbonImmutable $desde, CarbonImmutable $hasta, Collection $vencimientos): array
    {
        $cortes = $vencimientos
            ->pluck('fecha')
            ->map(fn (string $fecha): CarbonImmutable => CarbonImmutable::parse($fecha))
            ->filter(fn (CarbonImmutable $fecha): bool => $fecha->gt($desde) && $fecha->lt($hasta))
            ->push($hasta)
            ->unique(fn (CarbonImmutable $fecha): string => $fecha->toDateString())
            ->sort()
            ->values();

        $segmentos = [];
        $inicio = $desde;

        foreach ($cortes as $fin) {
            $segmentos[] = [$inicio, (int) $inicio->diffInDays($fin)];
            $inicio = $fin;
        }

        return $segmentos;
    }

    /**
     * Capital del cronograma vencido al inicio del día $fecha: el moratorio
     * corre desde el mismo día del vencimiento, así los días de atraso son
     * fecha de corte − vencimiento.
     *
     * @param  Collection<int, array{fecha: string, capital: string}>  $vencimientos
     */
    private function capitalExigible(Collection $vencimientos, CarbonImmutable $fecha): string
    {
        return $vencimientos
            ->filter(fn (array $vencimiento): bool => $vencimiento['fecha'] <= $fecha->toDateString())
            ->reduce(fn (string $total, array $vencimiento): string => bcadd($total, $vencimiento['capital'], self::ESCALA), '0');
    }

    /**
     * Tasa diaria equivalente a una tasa anual efectiva en %, año de 360 días.
     */
    private function tasaDiaria(string $tasaAnualPorcentaje): string
    {
        return sprintf('%.'.self::ESCALA.'F', (1 + (float) $tasaAnualPorcentaje / 100) ** (1 / 360) - 1);
    }

    private function redondear(string $monto): string
    {
        return bcadd($monto, bccomp($monto, '0', self::ESCALA) < 0 ? '-0.005' : '0.005', 2);
    }

    private function menor(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? bcadd($a, '0', 2) : bcadd($b, '0', 2);
    }
}
