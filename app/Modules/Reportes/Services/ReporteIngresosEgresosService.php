<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\Venta;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Estado de resultados por agencia: lo que la empresa GANA y lo que GASTA en
 * el periodo —no el flujo de caja—, cada grupo con su detalle línea a línea y
 * su total, más utilidad y margen.
 *
 * Ingresos (lo que se gana):
 *
 * - ingresos:  CajaMovimiento tipo=ingreso CON concepto del catálogo, que es
 *              exactamente lo que registra el módulo Ingresos
 *              (CajaService::registrarMovimiento()).
 * - cobranzas: el interés + mora de los créditos que se CANCELARON en el
 *              periodo, sumando todo lo que pagó la deuda en su vida (el
 *              crédito y los predecesores de su cadena: refrendos, adendas,
 *              pagos de cuota). Se reconoce en la fecha del cobro que lo
 *              canceló. El capital devuelto no es ganancia, y un crédito aún
 *              vigente todavía no aporta.
 * - ventas:    la ganancia de las ventas de tienda que quedaron PAGADAS en el
 *              periodo: precio de venta − capital prestado sobre el artículo.
 *
 * Egresos (lo que se gasta):
 *
 * - egresos:   CajaMovimiento tipo=egreso SIN credito_id — el módulo Egresos.
 *
 * Los desembolsos quedan fuera: prestar dinero no es un gasto, es capital que
 * vuelve. La planilla todavía no se suma (pendiente de ajustes en ese módulo).
 * El billetaje tampoco: es la entrega de efectivo de la bóveda al asesor.
 */
final class ReporteIngresosEgresosService
{
    public function __construct(private readonly CajaBovedaHierarchyService $hierarchy) {}

    /**
     * @return array{
     *     ingresos: array{filas: Collection<int, array<string, mixed>>, total: string},
     *     cobranzas: array{filas: Collection<int, array<string, mixed>>, total: string},
     *     ventas: array{filas: Collection<int, array<string, mixed>>, total: string},
     *     egresos: array{filas: Collection<int, array<string, mixed>>, total: string},
     *     totales: array<string, string>,
     *     utilidad_neta: string,
     *     margen_utilidad_bruta: float|null
     * }
     */
    public function ingresosEgresos(User $actor, string $desde, string $hasta, ?int $agenciaId = null): array
    {
        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->endOfDay();

        $secciones = [
            'ingresos' => $this->ingresos($inicio, $fin, $actor, $agenciaId),
            'cobranzas' => $this->cobranzas($inicio, $fin, $actor, $agenciaId),
            'ventas' => $this->ventas($inicio, $fin, $actor, $agenciaId),
            'egresos' => $this->egresos($inicio, $fin, $actor, $agenciaId),
        ];

        $totalIngresos = $this->sumar(
            $secciones['ingresos']['total'],
            $secciones['cobranzas']['total'],
            $secciones['ventas']['total'],
        );

        $totalEgresos = $secciones['egresos']['total'];
        $utilidad = bcsub($totalIngresos, $totalEgresos, 2);

        return [
            ...$secciones,
            'totales' => [
                ...$this->totalesDe($secciones),
                'total_ingresos' => $totalIngresos,
                'total_egresos' => $totalEgresos,
            ],
            'utilidad_neta' => $utilidad,
            // Ratio utilidad / ingresos (0.25 = 25%). Sin ingresos no hay
            // contra qué dividir, así que va null en vez de 0 — que en
            // pantalla se leería como "margen cero".
            'margen_utilidad_bruta' => bccomp($totalIngresos, '0', 2) === 0
                ? null
                : (float) bcdiv($utilidad, $totalIngresos, 4),
        ];
    }

    /**
     * @return array{filas: Collection<int, array<string, mixed>>, total: string}
     */
    private function ingresos(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId): array
    {
        $filas = $this->movimientosDeCaja($inicio, $fin, $actor, $agenciaId)
            ->where('tipo', 'ingreso')
            ->whereNotNull('concepto_id')
            ->get()
            ->map(fn (CajaMovimiento $m): array => $this->fila([
                'cuenta' => $this->nombre($m->registradoPor),
                'fecha' => $m->fecha_caja,
                'descripcion' => $this->componer([$m->concepto, $m->descripcion, $this->boleta($m)]),
                'monto' => $m->monto,
                'serie' => $m->serie,
                'numero' => $m->numero,
            ]));

        return $this->seccion($filas);
    }

    /**
     * @return array{filas: Collection<int, array<string, mixed>>, total: string}
     */
    private function egresos(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId): array
    {
        $filas = $this->movimientosDeCaja($inicio, $fin, $actor, $agenciaId)
            // egresosManuales() = tipo egreso SIN credito_id (ver
            // CajaMovimiento::scopeEgresosManuales()): el módulo Egresos.
            ->egresosManuales()
            ->get()
            ->map(fn (CajaMovimiento $m): array => $this->fila([
                'cuenta' => $this->nombre($m->registradoPor),
                'fecha' => $m->fecha_caja,
                'descripcion' => $this->componer([$m->concepto, $m->descripcion, $this->boleta($m)]),
                'monto' => $m->monto,
                'serie' => $m->serie,
                'numero' => $m->numero,
            ]));

        return $this->seccion($filas);
    }

    /**
     * Interés + mora de los créditos cancelados en el periodo. El cobro que
     * los canceló es el último no anulado de un crédito liquidado; su fecha de
     * caja y su caja deciden el periodo, la agencia y la visibilidad.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, total: string}
     */
    private function cobranzas(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId): array
    {
        $cancelaciones = Cobro::query()
            ->where('estado', '!=', 'anulado')
            ->whereHas('credito', fn (Builder $q) => $q->whereIn('estado', ['liquidado', 'liquidado_pendiente']))
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('cobros as posterior')
                ->whereColumn('posterior.credito_id', 'cobros.credito_id')
                ->whereColumn('posterior.id', '>', 'cobros.id')
                ->where('posterior.estado', '!=', 'anulado'))
            ->whereHas('cajaMovimiento', fn (Builder $q) => $this->enRango($q, $inicio, $fin))
            ->whereHas('cajaCiclo.caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('cajaCiclo.caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->with([
                'registradoPor:id,nombre,apellido',
                'cliente:id,nombre,apellido',
                'credito',
                'cajaMovimiento:id,fecha_caja',
            ])
            ->get();

        $cadenas = $cancelaciones->mapWithKeys(fn (Cobro $c): array => [$c->id => $c->credito->cadena()->pluck('id')]);

        $cobrosPorCredito = Cobro::query()
            ->whereIn('credito_id', $cadenas->flatten()->unique())
            ->where('estado', '!=', 'anulado')
            ->get(['credito_id', 'interes', 'mora'])
            ->groupBy('credito_id');

        $filas = $cancelaciones
            ->map(function (Cobro $cancelacion) use ($cadenas, $cobrosPorCredito): array {
                $cobros = $cadenas[$cancelacion->id]->flatMap(fn (int $id) => $cobrosPorCredito->get($id, collect()));
                $interes = $this->sumar(...$cobros->map(fn (Cobro $c): string => (string) $c->interes)->all());
                $mora = $this->sumar(...$cobros->map(fn (Cobro $c): string => (string) ($c->mora ?? '0'))->all());

                return $this->fila([
                    'cuenta' => $this->nombre($cancelacion->registradoPor),
                    'fecha' => $cancelacion->cajaMovimiento?->fecha_caja,
                    'descripcion' => $this->componer([
                        "Crédito {$cancelacion->credito->codigo}",
                        $this->nombre($cancelacion->cliente),
                        'Interés S/ '.number_format((float) $interes, 2).' + mora S/ '.number_format((float) $mora, 2),
                    ]),
                    'monto' => bcadd($interes, $mora, 2),
                ]);
            })
            ->filter(fn (array $fila): bool => bccomp($fila['monto'], '0', 2) > 0);

        return $this->seccion($filas);
    }

    /**
     * Ganancia de las ventas de tienda pagadas en el periodo: precio de venta
     * − capital prestado sobre el artículo (ver capitalDelArticulo()). Una
     * venta a pérdida resta.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, total: string}
     */
    private function ventas(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId): array
    {
        $filas = Venta::query()
            ->where('estado', 'pagada')
            ->whereDate('pagada_at', '>=', $inicio->toDateString())
            ->whereDate('pagada_at', '<=', $fin->toDateString())
            ->whereHas('pagos.cajaCiclo.caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->where('agencia_id', $agenciaId))
            ->with(['vendidoPor:id,nombre,apellido', 'cliente:id,nombre,apellido', 'creditoOrigen', 'articulo'])
            ->get()
            ->map(function (Venta $venta): array {
                $capital = $this->capitalDelArticulo($venta);

                return $this->fila([
                    'cuenta' => $this->nombre($venta->vendidoPor),
                    'fecha' => $venta->pagada_at,
                    'descripcion' => $this->componer([
                        $venta->creditoOrigen ? "Crédito {$venta->creditoOrigen->codigo}" : null,
                        $this->nombre($venta->cliente),
                        'Venta S/ '.number_format((float) $venta->precio_venta, 2).' − capital S/ '.number_format((float) $capital, 2),
                    ]),
                    'monto' => bcsub((string) $venta->precio_venta, $capital, 2),
                ]);
            });

        return $this->seccion($filas);
    }

    /**
     * Capital del crédito de origen que respaldaba el artículo vendido. Si el
     * crédito tenía varias garantías, su capital se reparte según la
     * valorización de cada una. Sin crédito de origen no hay capital que
     * descontar.
     */
    private function capitalDelArticulo(Venta $venta): string
    {
        $credito = $venta->creditoOrigen;

        if (! $credito instanceof Credito || ! $venta->articulo) {
            return '0.00';
        }

        $capital = (string) $credito->monto_prestamo;
        $valorizacionTotal = $this->sumar(
            ...$credito->garantiasComo($venta->articulo::class)->get()->map(fn (Model $g): string => (string) $g->valorizacion)->all(),
        );

        if (bccomp($valorizacionTotal, '0', 2) <= 0) {
            return bcadd($capital, '0', 2);
        }

        return bcdiv(bcmul($capital, (string) $venta->articulo->valorizacion, 6), $valorizacionTotal, 2);
    }

    /**
     * Query base de Ingresos y Egresos — las dos salen de
     * caja_movimientos con filtros distintos, pero comparten alcance del actor,
     * agencia y rango de fechas. El alcance es el de
     * CajaService::listarMovimientos(): un asesor solo ve su caja, un
     * administrador de agencia las de su agencia, un administrador general
     * todas las de su empresa.
     */
    private function movimientosDeCaja(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId): Builder
    {
        return CajaMovimiento::query()
            ->select([
                'id', 'caja_ciclo_id', 'tipo', 'monto', 'concepto', 'descripcion',
                'serie', 'numero', 'credito_id', 'registrado_por', 'fecha_caja',
            ])
            ->whereDate('fecha_caja', '>=', $inicio->toDateString())
            ->whereDate('fecha_caja', '<=', $fin->toDateString())
            ->whereHas('cajaCiclo.caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('cajaCiclo.caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->with('registradoPor:id,nombre,apellido');
    }

    /**
     * Rango de fechas sobre un query que YA es de caja_movimientos: se pasa
     * como closure a whereHas desde la fuente de Cobro, que llega hasta el
     * movimiento por relación en vez de por columna propia.
     */
    private function enRango(Builder $query, Carbon $inicio, Carbon $fin): void
    {
        $query
            ->whereDate('fecha_caja', '>=', $inicio->toDateString())
            ->whereDate('fecha_caja', '<=', $fin->toDateString());
    }

    /**
     * Una línea de la tabla del reporte: las 4 columnas del enunciado más la
     * serie y el número de la boleta, que ya se registran en el módulo
     * Ingresos/Egresos.
     *
     * @param  array{cuenta: string, fecha: mixed, descripcion: string, monto: mixed, serie?: ?string, numero?: ?string}  $fila
     * @return array{cuenta: string, fecha: string, descripcion: string, monto: string, serie: ?string, numero: ?string}
     */
    private function fila(array $fila): array
    {
        return [
            'cuenta' => $fila['cuenta'],
            'fecha' => $fila['fecha'] instanceof DateTimeInterface
                ? Carbon::instance($fila['fecha'])->toDateString()
                : '—',
            'descripcion' => $fila['descripcion'] !== '' ? $fila['descripcion'] : '—',
            'monto' => bcadd((string) ($fila['monto'] ?? '0'), '0', 2),
            'serie' => $fila['serie'] ?? null,
            'numero' => $fila['numero'] ?? null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array{filas: Collection<int, array<string, mixed>>, total: string}
     */
    private function seccion(Collection $filas): array
    {
        // Un estado de resultados se lee en orden cronológico; la cuenta
        // desempata para que dos operaciones del mismo día salgan siempre en
        // el mismo orden entre exportaciones.
        $ordenadas = $filas
            ->sortBy([['fecha', 'asc'], ['cuenta', 'asc'], ['descripcion', 'asc']])
            ->values();

        return ['filas' => $ordenadas, 'total' => $this->sumar(...$ordenadas->pluck('monto')->all())];
    }

    /**
     * Suma de montos con bcmath en vez de Collection::sum() (que acumula como
     * float): es un estado de resultados, y un centavo perdido por redondeo
     * binario se nota en la utilidad final.
     */
    private function sumar(string ...$montos): string
    {
        return array_reduce(
            $montos,
            fn (string $acumulado, string $monto): string => bcadd($acumulado, $monto, 2),
            '0.00',
        );
    }

    /**
     * @param  array<string, array{filas: Collection<int, array<string, mixed>>, total: string}>  $secciones
     * @return array<string, string>
     */
    private function totalesDe(array $secciones): array
    {
        $totales = [];

        foreach ($secciones as $clave => $seccion) {
            $totales[$clave] = $seccion['total'];
        }

        return $totales;
    }

    /**
     * Nombre de una persona — un User (la "cuenta" del reporte) o un Cliente
     * (en la descripción de cobranzas y ventas). Ambos tienen
     * nombre + apellido, así que comparten el mismo formato.
     */
    private function nombre(?Model $persona): string
    {
        return $persona ? trim("{$persona->nombre} {$persona->apellido}") : '—';
    }

    /**
     * Junta las piezas de una descripción en una sola celda ("concepto —
     * detalle — boleta"), descartando las vacías.
     *
     * @param  array<int, mixed>  $partes
     */
    private function componer(array $partes): string
    {
        return implode(' — ', array_filter(
            array_map(fn (mixed $parte): string => trim((string) $parte), $partes),
            fn (string $parte): bool => $parte !== '',
        ));
    }

    private function boleta(CajaMovimiento $movimiento): ?string
    {
        if ($movimiento->serie === null && $movimiento->numero === null) {
            return null;
        }

        return 'Boleta '.($movimiento->serie ?? '').'-'.($movimiento->numero ?? '');
    }
}
