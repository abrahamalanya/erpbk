<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\PagoVenta;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Estado de resultados por agencia: los 5 grupos de dinero que atraviesan las
 * cajas, cada uno con su detalle línea a línea y su total, más utilidad y
 * margen.
 *
 * Los 5 grupos son mutuamente excluyentes y salen todos de la misma fuente —el
 * movimiento de caja—, que es donde el dinero efectivamente entra y sale:
 *
 * - ingresos:    CajaMovimiento tipo=ingreso CON concepto del catálogo, que es
 *                exactamente lo que registra el módulo Ingresos
 *                (CajaService::registrarMovimiento()).
 * - cobranzas:   Cobro no anulado. Cada cobro genera su propio movimiento
 *                tipo=ingreso SIN concepto_id (CreditoService::registrarCobroEnCaja()),
 *                así que contarlo por acá —y no como ingreso— es lo que evita
 *                contarlo dos veces.
 * - ventas:      PagoVenta no anulado, misma lógica: VentaService::registrarPago()
 *                crea un movimiento tipo=ingreso sin concepto_id.
 * - egresos:     CajaMovimiento tipo=egreso SIN credito_id — el módulo Egresos.
 * - desembolsos: CajaMovimiento tipo=egreso CON credito_id — el módulo
 *                Desembolsos, generado por CreditoService::desembolsar(), el
 *                único punto del código que crea un egreso con credito_id.
 *
 * El billetaje (tipo=billetaje) queda fuera a propósito: es la entrega de
 * efectivo de la bóveda al asesor, no un ingreso. Ya reaparece dentro de su
 * caja como Ingresos, Cobranzas o Ventas, así que sumarlo lo duplicaría.
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
     *     desembolsos: array{filas: Collection<int, array<string, mixed>>, total: string},
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
            'desembolsos' => $this->desembolsos($inicio, $fin, $actor, $agenciaId),
        ];

        $totalIngresos = $this->sumar(
            $secciones['ingresos']['total'],
            $secciones['cobranzas']['total'],
            $secciones['ventas']['total'],
        );

        $totalEgresos = $this->sumar(
            $secciones['egresos']['total'],
            $secciones['desembolsos']['total'],
        );

        return [
            ...$secciones,
            'totales' => [
                ...$this->totalesDe($secciones),
                'total_ingresos' => $totalIngresos,
                'total_egresos' => $totalEgresos,
            ],
            'utilidad_neta' => bcsub($totalIngresos, $totalEgresos, 2),
            // Ratio, no porcentaje: ingresos sobre egresos. Sin egresos ni
            // desembolsos no hay contra qué dividir, así que va null en vez de
            // 0 — que en pantalla se leería como "no rentable".
            'margen_utilidad_bruta' => bccomp($totalEgresos, '0', 2) === 0
                ? null
                : (float) bcdiv($totalIngresos, $totalEgresos, 4),
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
     * @return array{filas: Collection<int, array<string, mixed>>, total: string}
     */
    private function desembolsos(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId): array
    {
        $filas = $this->movimientosDeCaja($inicio, $fin, $actor, $agenciaId)
            // desembolsos() = tipo egreso CON credito_id (el módulo Desembolsos).
            ->desembolsos()
            ->with(['credito.cliente:id,nombre,apellido'])
            ->get()
            ->map(fn (CajaMovimiento $m): array => $this->fila([
                'cuenta' => $this->nombre($m->registradoPor),
                'fecha' => $m->fecha_caja,
                'descripcion' => $this->componer([
                    $m->concepto,
                    $m->descripcion,
                    $this->nombre($m->credito?->cliente),
                ]),
                'monto' => $m->monto,
                'serie' => $m->serie,
                'numero' => $m->numero,
            ]));

        return $this->seccion($filas);
    }

    /**
     * @return array{filas: Collection<int, array<string, mixed>>, total: string}
     */
    private function cobranzas(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId): array
    {
        $filas = Cobro::query()
            ->where('estado', '!=', 'anulado')
            ->whereHas('cajaMovimiento', fn (Builder $q) => $this->enRango($q, $inicio, $fin))
            ->whereHas('cajaCiclo.caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('cajaCiclo.caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->with([
                'registradoPor:id,nombre,apellido',
                'cliente:id,nombre,apellido',
                'cajaMovimiento:id,concepto,fecha_caja',
            ])
            ->get(['id', 'caja_movimiento_id', 'cliente_id', 'registrado_por', 'monto_pagado'])
            ->map(fn (Cobro $c): array => $this->fila([
                'cuenta' => $this->nombre($c->registradoPor),
                // La fecha contable es la del ciclo de caja, no la del INSERT
                // del cobro: un cobro registrado con el caja reabierta a
                // destiempo pertenece al día que se cobró, no al día que se
                // escribió la fila.
                'fecha' => $c->cajaMovimiento?->fecha_caja,
                'descripcion' => $this->componer([
                    $c->cajaMovimiento?->concepto,
                    $this->nombre($c->cliente),
                ]),
                'monto' => $c->monto_pagado,
            ]));

        return $this->seccion($filas);
    }

    /**
     * Pagos recibidos sobre ventas de tienda. Se excluyen los de una venta
     * cancelada y los anulados individualmente: una venta cancelada no es
     * ingreso, por más que su pago siga en la tabla (VentaService::cancelar()
     * no da de baja los pagos). Es el mismo criterio con el que la cobranza
     * excluye los Cobro anulados.
     *
     * @return array{filas: Collection<int, array<string, mixed>>, total: string}
     */
    private function ventas(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId): array
    {
        $filas = PagoVenta::query()
            ->whereNull('anulado_at')
            ->whereHas('venta', fn (Builder $q) => $q->where('estado', '!=', 'cancelada'))
            ->whereHas('cajaMovimiento', fn (Builder $q) => $this->enRango($q, $inicio, $fin))
            ->whereHas('cajaCiclo.caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('cajaCiclo.caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->with([
                'registradoPor:id,nombre,apellido',
                'venta.cliente:id,nombre,apellido',
                'cajaMovimiento:id,concepto,fecha_caja',
            ])
            ->get(['id', 'venta_id', 'caja_movimiento_id', 'registrado_por', 'monto'])
            ->map(fn (PagoVenta $p): array => $this->fila([
                'cuenta' => $this->nombre($p->registradoPor),
                'fecha' => $p->cajaMovimiento?->fecha_caja,
                'descripcion' => $this->componer([
                    $p->cajaMovimiento?->concepto,
                    $this->nombre($p->venta?->cliente),
                ]),
                'monto' => $p->monto,
            ]));

        return $this->seccion($filas);
    }

    /**
     * Query base de Ingresos, Egresos y Desembolsos — las tres salen de
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
     * como closure a whereHas desde las fuentes de Cobro y PagoVenta, que
     * llegan hasta el movimiento por relación en vez de por columna propia.
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
     * (en la descripción de cobranzas, ventas y desembolsos). Ambos tienen
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
