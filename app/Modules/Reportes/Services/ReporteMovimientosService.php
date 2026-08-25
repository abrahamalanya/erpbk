<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\BovedaMovimiento;
use App\Modules\Caja\Models\CuentaBancariaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReporteMovimientosService
{
    public function __construct(private readonly CajaBovedaHierarchyService $hierarchy) {}

    /**
     * Every BovedaMovimiento (efectivo) y CuentaBancariaMovimiento (cuenta
     * bancaria) de las bóvedas visibles para $actor — mismo alcance que
     * CajaBovedaHierarchyService::bovedasVisibles() — mezclados en una sola
     * lista normalizada y ordenada por fecha descendente. A diferencia de
     * BovedaService::reporteInyecciones() (una sola bóveda, solo
     * inyección/traspaso), este cubre TODO movimiento de TODAS las bóvedas
     * visibles, por eso cada fila lleva su propia etiqueta de bóveda.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function movimientosDinero(User $actor, ?string $desde, ?string $hasta, ?string $medio, ?int $bovedaId): Collection
    {
        $bovedaIds = $this->hierarchy->bovedasVisibles(Boveda::query(), $actor)
            ->when($bovedaId, fn (Builder $q) => $q->where('id', $bovedaId))
            ->pluck('id');

        $efectivo = $medio === 'cuenta_bancaria'
            ? collect()
            : BovedaMovimiento::query()
                ->whereHas('bovedaCiclo', fn (Builder $q) => $q->whereIn('boveda_id', $bovedaIds))
                ->when($desde, fn (Builder $q) => $q->whereDate('fecha_boveda', '>=', $desde))
                ->when($hasta, fn (Builder $q) => $q->whereDate('fecha_boveda', '<=', $hasta))
                ->with(['registradoPor', 'bovedaCiclo.boveda.agencia'])
                ->get()
                ->map(fn (BovedaMovimiento $m): array => [
                    'id' => $m->id,
                    'medio' => 'efectivo',
                    'tipo' => $m->tipo,
                    'monto' => $m->monto,
                    'concepto' => $m->concepto,
                    'origen' => $m->origen,
                    'fecha' => $m->fecha_boveda->toDateString(),
                    'registrado_por' => $m->registradoPor,
                    'boveda' => $this->bovedaLabel($m->bovedaCiclo->boveda),
                    'cuenta_bancaria' => null,
                    'comprobante_url' => null,
                ]);

        $bancario = $medio === 'efectivo'
            ? collect()
            : CuentaBancariaMovimiento::query()
                ->whereHas('cuentaBancaria', fn (Builder $q) => $q->whereIn('boveda_id', $bovedaIds))
                ->when($desde, fn (Builder $q) => $q->whereDate('fecha', '>=', $desde))
                ->when($hasta, fn (Builder $q) => $q->whereDate('fecha', '<=', $hasta))
                ->with(['registradoPor', 'cuentaBancaria.banco', 'cuentaBancaria.boveda.agencia', 'fotos'])
                ->get()
                ->map(fn (CuentaBancariaMovimiento $m): array => [
                    'id' => $m->id,
                    'medio' => 'cuenta_bancaria',
                    'tipo' => $m->tipo,
                    'monto' => $m->monto,
                    'concepto' => $m->concepto,
                    'origen' => $m->origen,
                    'fecha' => $m->fecha->toDateString(),
                    'registrado_por' => $m->registradoPor,
                    'boveda' => $this->bovedaLabel($m->cuentaBancaria->boveda),
                    'cuenta_bancaria' => $m->cuentaBancaria,
                    'comprobante_url' => $m->fotos->first()?->url,
                ]);

        return $efectivo->concat($bancario)->sortByDesc('fecha')->values();
    }

    private function bovedaLabel(Boveda $boveda): string
    {
        return $boveda->tipo === 'principal' ? 'Bóveda principal' : ($boveda->agencia?->nombre ?? 'Agencia');
    }
}
