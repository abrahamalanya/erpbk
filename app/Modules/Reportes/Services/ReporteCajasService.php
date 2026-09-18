<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Usuario\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReporteCajasService
{
    /**
     * @var list<string>
     */
    private const MEDIOS_COBRANZA = ['efectivo', 'yape', 'plin', 'transferencia'];

    public function __construct(private readonly CajaBovedaHierarchyService $hierarchy) {}

    /**
     * Cada ciclo (apertura/cierre) de cada caja visible para $actor — mismo
     * alcance que CajaBovedaHierarchyService::cajasVisibles(). Los 5 totales
     * son mutuamente excluyentes entre sí:
     *
     * - total_ingresos / total_egresos: movimientos manuales (con un
     *   concepto del catálogo de la empresa — ver CajaService::registrarMovimiento()).
     * - total_billetaje: billetajes aprobados que entraron a este ciclo.
     * - total_cobranza: suma de Cobro::monto_pagado (no anulados) del ciclo —
     *   refrendos, liquidaciones, pagos de cuota, adendas, refinanciamientos.
     * - total_desembolso: egresos SIN concepto de catálogo (ver
     *   CreditoService::desembolsar()) — el único caso que genera un egreso
     *   así.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function aperturasCierres(
        User $actor,
        ?string $desde,
        ?string $hasta,
        ?int $agenciaId,
        ?string $estado,
    ): Collection {
        $query = CajaCiclo::query()
            ->whereHas('caja', fn (Builder $q) => $this->hierarchy->cajasVisibles($q, $actor))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->when($desde, fn (Builder $q) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn (Builder $q) => $q->whereDate('fecha', '<=', $hasta))
            ->when($estado, fn (Builder $q) => $q->where('estado', $estado))
            ->with(['caja.user', 'caja.agencia', 'cerradaPor'])
            ->withSum(['movimientos as total_ingresos' => fn (Builder $q) => $q->where('tipo', 'ingreso')->whereNotNull('concepto_id')], 'monto')
            ->withSum(['movimientos as total_egresos' => fn (Builder $q) => $q->where('tipo', 'egreso')->whereNotNull('concepto_id')], 'monto')
            ->withSum(['movimientos as total_desembolso' => fn (Builder $q) => $q->where('tipo', 'egreso')->whereNull('concepto_id')], 'monto')
            ->withSum(['movimientos as total_billetaje' => fn (Builder $q) => $q->where('tipo', 'billetaje')], 'monto')
            ->withSum(['cobros as total_cobranza' => fn (Builder $q) => $q->where('estado', '!=', 'anulado')], 'monto_pagado')
            ->latest('fecha')
            ->latest('abierta_at');

        return $query->get()->map(fn (CajaCiclo $ciclo): array => [
            'id' => $ciclo->id,
            'caja_id' => $ciclo->caja_id,
            'fecha' => $ciclo->fecha->toDateString(),
            'fecha_apertura' => $ciclo->abierta_at,
            'fecha_cierre' => $ciclo->cerrada_at,
            'usuario' => $ciclo->caja->user,
            'agencia' => $ciclo->caja->agencia?->nombre ?? 'Principal',
            'estado' => $ciclo->estado,
            'cierre_forzado' => $ciclo->cierre_forzado,
            'cierre_automatico' => $ciclo->cierre_automatico,
            'cerrada_por' => $ciclo->cerradaPor,
            'valor_aperturado' => $ciclo->saldo_apertura,
            'valor_cerrado' => $ciclo->saldo_arqueo_cierre,
            'saldo_efectivo_cierre' => $ciclo->saldo_efectivo_cierre,
            'diferencia' => $ciclo->diferencia,
            'total_ingresos' => $this->formatMonto($ciclo->total_ingresos),
            'total_egresos' => $this->formatMonto($ciclo->total_egresos),
            'total_billetaje' => $this->formatMonto($ciclo->total_billetaje),
            'total_cobranza' => $this->formatMonto($ciclo->total_cobranza),
            'total_desembolso' => $this->formatMonto($ciclo->total_desembolso),
        ]);
    }

    /**
     * El detalle línea por línea de un ciclo: cada billetaje aprobado,
     * ingreso, egreso, cobranza (agrupada por medio) y desembolso que lo
     * compone, más el cálculo de saldo:
     *
     *   SALDO TOTAL = billetaje + ingresos + cobranzas (todos los medios) - egresos - desembolsos
     *
     * que es exactamente lo que ya calcula CajaCiclo::saldoActual() (la
     * suma de todo movimiento tipo=ingreso/billetaje menos todo tipo=egreso;
     * ingresos+cobranza cubren el primer grupo sin solaparse, egresos+desembolso
     * el segundo), así que se reusa directamente en vez de rehacer la cuenta.
     *
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function detalle(User $actor, CajaCiclo $ciclo): array
    {
        if (! $this->esVisible($actor, $ciclo)) {
            throw new AuthorizationException('No tienes acceso a esta caja.');
        }

        $billetajes = Billetaje::query()
            ->where('caja_ciclo_id', $ciclo->id)
            ->where('estado', 'aprobado')
            ->with(['solicitadoPor', 'aprobadoPor'])
            ->orderBy('fecha_resolucion')
            ->get();

        $ingresos = CajaMovimiento::query()
            ->where('caja_ciclo_id', $ciclo->id)->where('tipo', 'ingreso')->whereNotNull('concepto_id')
            ->with(['registradoPor', 'fotos'])->orderBy('created_at')->get();

        $egresos = CajaMovimiento::query()
            ->where('caja_ciclo_id', $ciclo->id)->where('tipo', 'egreso')->whereNotNull('concepto_id')
            ->with(['registradoPor', 'fotos'])->orderBy('created_at')->get();

        $desembolsos = CajaMovimiento::query()
            ->where('caja_ciclo_id', $ciclo->id)->where('tipo', 'egreso')->whereNull('concepto_id')
            ->with(['registradoPor', 'credito.cliente'])->orderBy('created_at')->get();

        $cobrosPorMedio = Cobro::query()
            ->where('caja_ciclo_id', $ciclo->id)->where('estado', '!=', 'anulado')
            ->with(['cliente', 'registradoPor'])->orderBy('created_at')->get()
            ->groupBy('medio');

        $cobranzas = collect(self::MEDIOS_COBRANZA)->mapWithKeys(fn (string $medio): array => [
            $medio => ($cobrosPorMedio->get($medio) ?? collect())->map(fn (Cobro $c): array => $this->mapCobro($c))->values(),
        ]);

        $totalesCobranza = $cobranzas->map(fn (Collection $items): string => $this->formatMonto((string) $items->sum('monto')));

        $saldoTotal = $this->formatMonto($ciclo->saldoActual());
        $saldoCierre = $ciclo->saldo_arqueo_cierre !== null ? (string) $ciclo->saldo_arqueo_cierre : null;

        return [
            'billetajes' => $billetajes->map(fn (Billetaje $b): array => [
                'id' => $b->id,
                'fecha' => $b->fecha_resolucion,
                'motivo' => $b->motivo,
                'monto' => (string) $b->monto,
                'medio_recepcion' => $b->medio_recepcion,
                'datos_recepcion' => $b->datos_recepcion,
                'solicitado_por' => $b->solicitadoPor,
                'aprobado_por' => $b->aprobadoPor,
            ])->values(),
            'total_billetaje' => $this->formatMonto((string) $billetajes->sum('monto')),
            'ingresos' => $ingresos->map(fn (CajaMovimiento $m): array => $this->mapMovimiento($m))->values(),
            'total_ingresos' => $this->formatMonto((string) $ingresos->sum('monto')),
            'egresos' => $egresos->map(fn (CajaMovimiento $m): array => $this->mapMovimiento($m))->values(),
            'total_egresos' => $this->formatMonto((string) $egresos->sum('monto')),
            'cobranzas' => $cobranzas,
            'totales_cobranza' => $totalesCobranza,
            'desembolsos' => $desembolsos->map(fn (CajaMovimiento $m): array => [
                'id' => $m->id,
                'fecha' => $m->created_at,
                'cliente' => $m->credito?->cliente,
                'monto' => (string) $m->monto,
                'credito_id' => $m->credito_id,
                'concepto' => $m->concepto,
                'registrado_por' => $m->registradoPor,
            ])->values(),
            'total_desembolso' => $this->formatMonto((string) $desembolsos->sum('monto')),
            'saldo_total' => $saldoTotal,
            'saldo_cierre' => $saldoCierre,
            'diferencia' => $saldoCierre !== null ? bcsub($saldoCierre, $saldoTotal, 2) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMovimiento(CajaMovimiento $m): array
    {
        return [
            'id' => $m->id,
            'fecha' => $m->created_at,
            'concepto' => $m->concepto,
            'monto' => (string) $m->monto,
            'descripcion' => $m->descripcion,
            'registrado_por' => $m->registradoPor,
            'comprobante_url' => $m->fotos->firstWhere('tipo', 'comprobante')?->url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCobro(Cobro $c): array
    {
        return [
            'id' => $c->id,
            'fecha' => $c->created_at,
            'cliente' => $c->cliente,
            'monto' => (string) $c->monto_pagado,
            'credito_id' => $c->credito_id,
            'operacion' => $c->operacion,
            'interes' => $c->interes !== null ? (string) $c->interes : null,
            'mora' => $c->mora !== null ? (string) $c->mora : null,
            'descuento' => $c->descuento !== null ? (string) $c->descuento : null,
            'medio' => $c->medio,
            'registrado_por' => $c->registradoPor,
        ];
    }

    private function esVisible(User $actor, CajaCiclo $ciclo): bool
    {
        $query = Caja::query()->whereKey($ciclo->caja_id);

        return $this->hierarchy->cajasVisibles($query, $actor)->exists();
    }

    /**
     * withSum() aggregates come back as a raw number (or null when there are
     * no matching rows), unlike the model's own decimal:2-cast columns —
     * normalize to the same "0.00"-string shape the frontend already expects.
     */
    private function formatMonto(string|int|float|null $monto): string
    {
        return bcadd((string) ($monto ?? '0'), '0', 2);
    }
}
