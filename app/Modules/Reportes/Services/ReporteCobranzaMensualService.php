<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class ReporteCobranzaMensualService
{
    /**
     * Cobranza y desembolsos del mes, uno y otro por día — mismos filtros
     * (empresa/agencia/asesor) para ambos gráficos de barras horizontales
     * del reporte. empresa_id solo tiene efecto para 'sistemas': el resto de
     * roles ya está confinado a su propia empresa por el TenantScope de
     * Cobro/CajaMovimiento.
     *
     * @return array{
     *     cobranza: Collection<int, array{dia: int, total_cobrado: float}>,
     *     desembolsos: Collection<int, array{dia: int, total_desembolsado: float}>,
     *     cobranzaPorAsesor: Collection<int, array{asesor_id: int, asesor_nombre: string, total_cobrado: float}>,
     *     desembolsosPorAsesor: Collection<int, array{asesor_id: int, asesor_nombre: string, total_desembolsado: float}>,
     * }
     */
    public function cobranzaMensual(
        User $actor,
        string $mes,
        ?int $empresaId,
        ?int $agenciaId,
        ?int $asesorId,
    ): array {
        $inicioMes = Carbon::parse("{$mes}-01")->startOfMonth();
        $finMes = $inicioMes->copy()->endOfMonth();

        $cobros = $this->cobrosEnRango($inicioMes, $finMes, $actor, $empresaId, $agenciaId, $asesorId);
        $desembolsos = $this->desembolsosEnRango($inicioMes, $finMes, $actor, $empresaId, $agenciaId, $asesorId);

        return [
            'cobranza' => $this->rellenar(
                range(1, $inicioMes->daysInMonth),
                $this->totalesPorClave($cobros, fn (Cobro $c): int => $c->created_at->day, fn (Cobro $c): float => (float) $c->monto_pagado),
                'dia',
                'total_cobrado',
            ),
            'desembolsos' => $this->rellenar(
                range(1, $inicioMes->daysInMonth),
                $this->totalesPorClave($desembolsos, fn (CajaMovimiento $m): int => $m->fecha_caja->day, fn (CajaMovimiento $m): float => (float) $m->monto),
                'dia',
                'total_desembolsado',
            ),
            'cobranzaPorAsesor' => $this->totalesPorAsesor($cobros, fn (Cobro $c): float => (float) $c->monto_pagado, 'total_cobrado'),
            'desembolsosPorAsesor' => $this->totalesPorAsesor($desembolsos, fn (CajaMovimiento $m): float => (float) $m->monto, 'total_desembolsado'),
        ];
    }

    /**
     * Vista anual: mismos filtros, pero cobranza y desembolsos por mes
     * (enero a diciembre) del año indicado — la barra vertical que resume
     * cuánto se hace cada mes, para elegir de un vistazo qué mes mirar en
     * detalle con cobranzaMensual().
     *
     * @return array{
     *     cobranza: Collection<int, array{mes: int, total_cobrado: float}>,
     *     desembolsos: Collection<int, array{mes: int, total_desembolsado: float}>,
     *     cobranzaPorAsesor: Collection<int, array{asesor_id: int, asesor_nombre: string, total_cobrado: float}>,
     *     desembolsosPorAsesor: Collection<int, array{asesor_id: int, asesor_nombre: string, total_desembolsado: float}>,
     * }
     */
    public function cobranzaAnual(
        User $actor,
        int $anio,
        ?int $empresaId,
        ?int $agenciaId,
        ?int $asesorId,
    ): array {
        $inicioAnio = Carbon::create($anio, 1, 1)->startOfYear();
        $finAnio = $inicioAnio->copy()->endOfYear();

        $cobros = $this->cobrosEnRango($inicioAnio, $finAnio, $actor, $empresaId, $agenciaId, $asesorId);
        $desembolsos = $this->desembolsosEnRango($inicioAnio, $finAnio, $actor, $empresaId, $agenciaId, $asesorId);

        return [
            'cobranza' => $this->rellenar(
                range(1, 12),
                $this->totalesPorClave($cobros, fn (Cobro $c): int => $c->created_at->month, fn (Cobro $c): float => (float) $c->monto_pagado),
                'mes',
                'total_cobrado',
            ),
            'desembolsos' => $this->rellenar(
                range(1, 12),
                $this->totalesPorClave($desembolsos, fn (CajaMovimiento $m): int => $m->fecha_caja->month, fn (CajaMovimiento $m): float => (float) $m->monto),
                'mes',
                'total_desembolsado',
            ),
            'cobranzaPorAsesor' => $this->totalesPorAsesor($cobros, fn (Cobro $c): float => (float) $c->monto_pagado, 'total_cobrado'),
            'desembolsosPorAsesor' => $this->totalesPorAsesor($desembolsos, fn (CajaMovimiento $m): float => (float) $m->monto, 'total_desembolsado'),
        ];
    }

    /**
     * @return Collection<int, Cobro>
     */
    private function cobrosEnRango(Carbon $inicio, Carbon $fin, User $actor, ?int $empresaId, ?int $agenciaId, ?int $asesorId): Collection
    {
        return Cobro::query()
            ->where('estado', '!=', 'anulado')
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($actor->hasRole('sistemas') && $empresaId, fn (Builder $q) => $q->where('empresa_id', $empresaId))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('credito', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->when($asesorId, fn (Builder $q) => $q->where('registrado_por', $asesorId))
            ->with('registradoPor:id,nombre,apellido')
            ->get(['id', 'created_at', 'monto_pagado', 'registrado_por']);
    }

    /**
     * Desembolso = egreso de caja sin concepto del catálogo — el único caso
     * que lo genera es CreditoService::desembolsar() (ver
     * ReporteCajasService::aperturasCierres()).
     *
     * @return Collection<int, CajaMovimiento>
     */
    private function desembolsosEnRango(Carbon $inicio, Carbon $fin, User $actor, ?int $empresaId, ?int $agenciaId, ?int $asesorId): Collection
    {
        return CajaMovimiento::query()
            ->where('tipo', 'egreso')
            ->whereNull('concepto_id')
            // whereBetween con solo la fecha (no whereDate) fallaría cuando
            // $inicio == $fin: fecha_caja se guarda con hora ("... 00:00:00"),
            // así que el límite superior en formato "Y-m-d" queda
            // lexicográficamente por debajo y excluye ese día completo.
            ->whereDate('fecha_caja', '>=', $inicio->toDateString())
            ->whereDate('fecha_caja', '<=', $fin->toDateString())
            ->when($actor->hasRole('sistemas') && $empresaId, fn (Builder $q) => $q->where('empresa_id', $empresaId))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('credito', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->when($asesorId, fn (Builder $q) => $q->where('registrado_por', $asesorId))
            ->with('registradoPor:id,nombre,apellido')
            ->get(['id', 'fecha_caja', 'monto', 'registrado_por']);
    }

    /**
     * Se agrupa en PHP (en vez de DAY()/MONTH() en SQL) para no atarse a la
     * sintaxis de un motor de BD — los tests corren contra SQLite,
     * producción contra MySQL, y el volumen es chico.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $items
     * @param  callable(TModel): int  $claveDe
     * @param  callable(TModel): float  $montoDe
     * @return Collection<int, float> total por clave (solo las que tienen movimientos)
     */
    private function totalesPorClave(Collection $items, callable $claveDe, callable $montoDe): Collection
    {
        return $items->groupBy($claveDe)->map(fn (Collection $grupo): float => (float) $grupo->sum($montoDe));
    }

    /**
     * Rellena las claves sin movimientos con 0 — el gráfico de barras
     * necesita el eje completo (todos los días del mes, o los 12 meses del
     * año), no solo los que tienen datos.
     *
     * @param  iterable<int>  $claves
     * @return Collection<int, array<string, int|float>>
     */
    private function rellenar(iterable $claves, Collection $totalesPorClave, string $campoClave, string $campoTotal): Collection
    {
        return collect($claves)->map(fn (int $clave): array => [
            $campoClave => $clave,
            $campoTotal => (float) ($totalesPorClave[$clave] ?? 0),
        ]);
    }

    /**
     * Comparación entre asesores (gráfico de pastel en el frontend) — a
     * diferencia de rellenar(), no se completan los asesores sin movimientos:
     * solo tiene sentido listar a quien cobró/desembolsó algo. Sin relleno de
     * ceros ni orden — el frontend decide cómo truncar/ordenar para el chart.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $items  con registrado_por y registradoPor cargados
     * @param  callable(TModel): float  $montoDe
     * @return Collection<int, array{asesor_id: int, asesor_nombre: string}>
     */
    private function totalesPorAsesor(Collection $items, callable $montoDe, string $campoTotal): Collection
    {
        return $items
            ->whereNotNull('registrado_por')
            ->groupBy('registrado_por')
            ->map(function (Collection $grupo) use ($montoDe, $campoTotal): array {
                $asesor = $grupo->first()->registradoPor;

                return [
                    'asesor_id' => $asesor->id,
                    'asesor_nombre' => trim("{$asesor->nombre} {$asesor->apellido}"),
                    $campoTotal => (float) $grupo->sum($montoDe),
                ];
            })
            ->values();
    }
}
