<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class ReporteFlujoCajaService
{
    /**
     * @var list<string>
     */
    private const CATEGORIAS = ['billetaje', 'ingresos', 'egresos', 'cobranza', 'desembolsos'];

    public function __construct(private readonly CajaBovedaHierarchyService $hierarchy) {}

    /**
     * Saldo de caja (en vivo, no filtrado por fecha) + totales y desglose
     * por asesor de las 5 categorías del rango de fechas indicado.
     *
     * @return array{
     *     saldo_caja: float,
     *     totales: array<string, float>,
     *     porAsesor: array<string, Collection<int, array{asesor_id: int, asesor_nombre: string, monto: float}>>,
     * }
     */
    public function flujoCaja(User $actor, string $desde, string $hasta, ?int $agenciaId, ?int $asesorId): array
    {
        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->endOfDay();

        $movimientos = $this->movimientosPorCategoria($inicio, $fin, $actor, $agenciaId, $asesorId);

        $totales = [];
        $porAsesor = [];

        foreach ($movimientos as $categoria => $datos) {
            [$items, $idDe, $userDe, $montoDe] = $datos;
            $totales[$categoria] = (float) $items->sum($montoDe);
            $porAsesor[$categoria] = $this->totalesPorAsesor($items, $idDe, $userDe, $montoDe);
        }

        return [
            'saldo_caja' => $this->saldoCajaVisible($actor, $agenciaId, $asesorId),
            'totales' => $totales,
            'porAsesor' => $porAsesor,
        ];
    }

    /**
     * Mismas 5 categorías, agregadas por mes (enero a diciembre) del año
     * indicado — la data del LineChart de resumen anual.
     *
     * @return array{porMes: Collection<int, array<string, int|float>>}
     */
    public function flujoCajaAnual(User $actor, int $anio, ?int $agenciaId, ?int $asesorId): array
    {
        $inicio = Carbon::create($anio, 1, 1)->startOfYear();
        $fin = $inicio->copy()->endOfYear();

        $movimientos = $this->movimientosPorCategoria($inicio, $fin, $actor, $agenciaId, $asesorId);
        $porMes = $this->porPeriodo($movimientos, range(1, 12), 'mes', fn (Carbon $fecha): int => $fecha->month);

        return ['porMes' => $porMes];
    }

    /**
     * Mismas 5 categorías, agregadas por día del mes indicado — la data del
     * LineChart de resumen mensual (todos los días del mes filtrado).
     *
     * @return array{porDia: Collection<int, array<string, int|float>>}
     */
    public function flujoCajaMensual(User $actor, string $mes, ?int $agenciaId, ?int $asesorId): array
    {
        $inicio = Carbon::parse("{$mes}-01")->startOfMonth();
        $fin = $inicio->copy()->endOfMonth();

        $movimientos = $this->movimientosPorCategoria($inicio, $fin, $actor, $agenciaId, $asesorId);
        $porDia = $this->porPeriodo($movimientos, range(1, $inicio->daysInMonth), 'dia', fn (Carbon $fecha): int => $fecha->day);

        return ['porDia' => $porDia];
    }

    /**
     * Agrega las 5 categorías por la clave que extraiga $claveDe de la fecha
     * de cada movimiento (día del mes o mes del año) y rellena las claves
     * sin movimientos con 0 — reusado por flujoCajaAnual() y
     * flujoCajaMensual(), que solo difieren en el rango de fechas y en qué
     * parte de la fecha agrupan.
     *
     * @param  array<string, array{0: Collection<int, Model>, 1: callable, 2: callable, 3: callable, 4: callable}>  $movimientosPorCategoria
     * @param  iterable<int>  $claves
     * @param  callable(Carbon): int  $claveDe
     * @return Collection<int, array<string, int|float>>
     */
    private function porPeriodo(array $movimientosPorCategoria, iterable $claves, string $campoClave, callable $claveDe): Collection
    {
        $totalesPorClavePorCategoria = [];
        foreach ($movimientosPorCategoria as $categoria => $datos) {
            [$items, , , $montoDe, $fechaDe] = $datos;
            $totalesPorClavePorCategoria[$categoria] = $items->groupBy(fn (Model $item): int => $claveDe($fechaDe($item)))
                ->map(fn (Collection $grupo): float => (float) $grupo->sum($montoDe));
        }

        return collect($claves)->map(function (int $clave) use ($totalesPorClavePorCategoria, $campoClave): array {
            $fila = [$campoClave => $clave];
            foreach (self::CATEGORIAS as $categoria) {
                $fila[$categoria] = (float) ($totalesPorClavePorCategoria[$categoria][$clave] ?? 0);
            }

            return $fila;
        });
    }

    /**
     * Arma las 5 categorías con la misma forma: [items, extractor de id de
     * asesor, extractor del User, extractor de monto, extractor de fecha] —
     * fuente única que consumen tanto flujoCaja() (agrupa por asesor) como
     * flujoCajaAnual() (agrupa por mes).
     *
     * @return array<string, array{0: Collection<int, Model>, 1: callable, 2: callable, 3: callable, 4: callable}>
     */
    private function movimientosPorCategoria(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId, ?int $asesorId): array
    {
        $billetajes = $this->billetajesEnRango($inicio, $fin, $actor, $agenciaId, $asesorId);
        $ingresos = $this->cajaMovimientosEnRango('ingreso', true, $inicio, $fin, $actor, $agenciaId, $asesorId);
        $egresos = $this->cajaMovimientosEnRango('egreso', true, $inicio, $fin, $actor, $agenciaId, $asesorId);
        $desembolsos = $this->cajaMovimientosEnRango('egreso', false, $inicio, $fin, $actor, $agenciaId, $asesorId);
        $cobros = $this->cobrosEnRango($inicio, $fin, $actor, $agenciaId, $asesorId);

        return [
            'billetaje' => [
                $billetajes,
                fn (CajaMovimiento $m): ?int => $m->billetaje?->solicitado_por,
                fn (CajaMovimiento $m): ?User => $m->billetaje?->solicitadoPor,
                fn (CajaMovimiento $m): float => (float) $m->monto,
                fn (CajaMovimiento $m): Carbon => $m->fecha_caja,
            ],
            'ingresos' => [
                $ingresos,
                fn (CajaMovimiento $m): ?int => $m->registrado_por,
                fn (CajaMovimiento $m): ?User => $m->registradoPor,
                fn (CajaMovimiento $m): float => (float) $m->monto,
                fn (CajaMovimiento $m): Carbon => $m->fecha_caja,
            ],
            'egresos' => [
                $egresos,
                fn (CajaMovimiento $m): ?int => $m->registrado_por,
                fn (CajaMovimiento $m): ?User => $m->registradoPor,
                fn (CajaMovimiento $m): float => (float) $m->monto,
                fn (CajaMovimiento $m): Carbon => $m->fecha_caja,
            ],
            'cobranza' => [
                $cobros,
                fn (Cobro $c): ?int => $c->registrado_por,
                fn (Cobro $c): ?User => $c->registradoPor,
                fn (Cobro $c): float => (float) $c->monto_pagado,
                fn (Cobro $c): Carbon => $c->created_at,
            ],
            'desembolsos' => [
                $desembolsos,
                fn (CajaMovimiento $m): ?int => $m->registrado_por,
                fn (CajaMovimiento $m): ?User => $m->registradoPor,
                fn (CajaMovimiento $m): float => (float) $m->monto,
                fn (CajaMovimiento $m): Carbon => $m->fecha_caja,
            ],
        ];
    }

    /**
     * Ingresos (con concepto del catálogo), egresos (ídem) y desembolsos
     * (egreso SIN concepto — lo genera CreditoService::desembolsar()) son la
     * misma query con un filtro de concepto_id distinto. Un cobro también
     * genera un CajaMovimiento tipo=ingreso pero sin concepto_id (ver
     * CreditoService::registrarCobroEnCaja()), por eso $conConceptoNoNulo
     * evita que se cuente dos veces como "Ingresos" y "Cobranza".
     *
     * @return Collection<int, CajaMovimiento>
     */
    private function cajaMovimientosEnRango(
        string $tipo,
        bool $conConceptoNoNulo,
        Carbon $inicio,
        Carbon $fin,
        User $actor,
        ?int $agenciaId,
        ?int $asesorId,
    ): Collection {
        return CajaMovimiento::query()
            ->where('tipo', $tipo)
            ->when($conConceptoNoNulo, fn (Builder $q) => $q->whereNotNull('concepto_id'), fn (Builder $q) => $q->whereNull('concepto_id'))
            ->whereDate('fecha_caja', '>=', $inicio->toDateString())
            ->whereDate('fecha_caja', '<=', $fin->toDateString())
            ->whereHas('cajaCiclo.caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('cajaCiclo.caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->when($asesorId, fn (Builder $q) => $q->where('registrado_por', $asesorId))
            ->with('registradoPor:id,nombre,apellido')
            ->get(['id', 'monto', 'registrado_por', 'fecha_caja']);
    }

    /**
     * @return Collection<int, CajaMovimiento>
     */
    private function billetajesEnRango(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId, ?int $asesorId): Collection
    {
        return CajaMovimiento::query()
            ->where('tipo', 'billetaje')
            ->whereDate('fecha_caja', '>=', $inicio->toDateString())
            ->whereDate('fecha_caja', '<=', $fin->toDateString())
            ->whereHas('cajaCiclo.caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('cajaCiclo.caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->when($asesorId, fn (Builder $q) => $q->whereHas('billetaje', fn (Builder $b) => $b->where('solicitado_por', $asesorId)))
            ->with('billetaje:id,solicitado_por', 'billetaje.solicitadoPor:id,nombre,apellido')
            ->get(['id', 'monto', 'billetaje_id', 'fecha_caja']);
    }

    /**
     * @return Collection<int, Cobro>
     */
    private function cobrosEnRango(Carbon $inicio, Carbon $fin, User $actor, ?int $agenciaId, ?int $asesorId): Collection
    {
        return Cobro::query()
            ->where('estado', '!=', 'anulado')
            ->whereBetween('created_at', [$inicio, $fin])
            ->whereHas('cajaCiclo.caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('cajaCiclo.caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->when($asesorId, fn (Builder $q) => $q->where('registrado_por', $asesorId))
            ->with('registradoPor:id,nombre,apellido')
            ->get(['id', 'monto_pagado', 'registrado_por', 'created_at']);
    }

    /**
     * Suma de CajaCiclo::saldoActual() de los ciclos abiertos visibles — un
     * saldo en vivo, no filtrado por rango de fecha (el saldo real de una
     * caja no depende de qué días esté mirando el reporte).
     */
    private function saldoCajaVisible(User $actor, ?int $agenciaId, ?int $asesorId): float
    {
        $ciclosAbiertos = CajaCiclo::query()
            ->where('estado', 'abierta')
            ->whereHas('caja', function (Builder $q) use ($actor, $agenciaId, $asesorId): void {
                $this->hierarchy->cajasVisibles($q, $actor);
                $q->when($agenciaId, fn (Builder $c) => $c->where('agencia_id', $agenciaId));
                $q->when($asesorId, fn (Builder $c) => $c->where('user_id', $asesorId));
            })
            ->get();

        return (float) $ciclosAbiertos->sum(fn (CajaCiclo $c): float => (float) $c->saldoActual());
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $items
     * @param  callable(TModel): ?int  $idDe
     * @param  callable(TModel): ?User  $userDe
     * @param  callable(TModel): float  $montoDe
     * @return Collection<int, array{asesor_id: int, asesor_nombre: string, monto: float}>
     */
    private function totalesPorAsesor(Collection $items, callable $idDe, callable $userDe, callable $montoDe): Collection
    {
        return $items
            ->filter(fn (Model $item): bool => $idDe($item) !== null)
            ->groupBy($idDe)
            ->map(function (Collection $grupo) use ($userDe, $montoDe): array {
                /** @var User $asesor */
                $asesor = $userDe($grupo->first());

                return [
                    'asesor_id' => $asesor->id,
                    'asesor_nombre' => trim("{$asesor->nombre} {$asesor->apellido}"),
                    'monto' => (float) $grupo->sum($montoDe),
                ];
            })
            ->sortByDesc('monto')
            ->values();
    }
}
