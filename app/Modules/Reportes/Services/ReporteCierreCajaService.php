<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Usuario\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class ReporteCierreCajaService
{
    public function __construct(private readonly CajaBovedaHierarchyService $hierarchy) {}

    /** @return array<string, mixed> */
    public function reporte(User $actor, CajaCiclo $ciclo): array
    {
        if (! $this->hierarchy->cajasVisibles(Caja::query()->whereKey($ciclo->caja_id), $actor)->exists()) {
            throw new AuthorizationException('No tienes acceso a esta caja.');
        }

        $ciclo->load(['caja.user', 'caja.agencia', 'cerradaPor']);
        $movimientos = $ciclo->movimientos()->with(['fotos', 'credito.cliente', 'billetaje.solicitadoPor'])
            ->orderBy('created_at')->orderBy('id')->get();
        $cobros = $ciclo->cobros()->where('estado', '!=', 'anulado')
            ->with(['cliente', 'credito.cuotas', 'credito.cobros' => fn ($query) => $query->where('estado', '!=', 'anulado')->where('operacion', 'liquidacion')])->orderBy('created_at')->orderBy('id')->get();

        // Los movimientos de cobros anulados tampoco son ingresos manuales.
        $movimientosCobro = $ciclo->cobros()->whereNotNull('caja_movimiento_id')->pluck('caja_movimiento_id');
        $ingresos = $movimientos->filter(fn (CajaMovimiento $m): bool => $m->tipo === 'ingreso' && ! $movimientosCobro->contains($m->id));
        $billetajes = $movimientos->where('tipo', 'billetaje');
        $desembolsos = $movimientos->filter(fn (CajaMovimiento $m): bool => $m->tipo === 'egreso' && $m->credito_id !== null);
        $gastos = $movimientos->filter(fn (CajaMovimiento $m): bool => $m->tipo === 'egreso' && $m->credito_id === null);
        $gastosMapeados = $gastos->map(fn (CajaMovimiento $m): array => $this->movimiento($m))->values();

        $fechaCorte = $ciclo->cerrada_at ?? now();
        $porMedio = $cobros->groupBy('medio');
        $medios = collect(['efectivo', 'yape', 'plin', 'transferencia'])->merge($porMedio->keys())->unique();
        $pagos = $medios->mapWithKeys(fn (string $medio): array => [
            $medio => ($porMedio->get($medio) ?? collect())->map(fn (Cobro $c): array => [
                'id' => $c->id,
                'fecha' => $c->created_at,
                'hora' => $c->created_at->timezone('America/Lima')->format('H:i'),
                'cliente' => $c->cliente,
                'credito_id' => $c->credito_id,
                'credito_codigo' => $c->credito?->codigo,
                'estado_credito_al_cobrar' => $c->credito_estado_anterior,
                ...$this->estadoCredito($c, $fechaCorte, $ciclo->fecha),
                'operacion' => $c->operacion,
                'monto' => $c->monto_pagado,
                'medio' => $c->medio,
                'interes' => $c->interes,
                'mora' => $c->mora,
                'descuento' => $c->descuento,
            ])->values(),
        ]);

        $saldoTotal = $ciclo->estado === 'cerrada' && $ciclo->saldo_calculado_cierre !== null
            ? $ciclo->saldo_calculado_cierre : $ciclo->saldoActual();
        $saldoEfectivo = $ciclo->estado === 'cerrada' && $ciclo->saldo_efectivo_cierre !== null
            ? $ciclo->saldo_efectivo_cierre : $ciclo->saldoEfectivo();
        $diferencia = $ciclo->saldo_arqueo_cierre !== null
            ? bcsub($ciclo->saldo_arqueo_cierre, $saldoEfectivo, 2) : null;
        $faltante = $diferencia !== null && bccomp($diferencia, '0', 2) < 0 ? bcsub('0', $diferencia, 2) : '0.00';
        $sobrante = $diferencia !== null && bccomp($diferencia, '0', 2) > 0 ? $diferencia : '0.00';

        return [
            'cierre' => [
                'id' => $ciclo->id,
                'caja_id' => $ciclo->caja_id,
                'fecha' => $ciclo->fecha->toDateString(),
                'estado' => $ciclo->estado,
                'fecha_apertura' => $ciclo->abierta_at,
                'fecha_cierre' => $ciclo->cerrada_at,
                'hora_apertura' => $ciclo->abierta_at?->timezone('America/Lima')->format('H:i'),
                'hora_cierre' => $ciclo->cerrada_at?->timezone('America/Lima')->format('H:i'),
                'cierre_forzado' => $ciclo->cierre_forzado,
                'cierre_automatico' => $ciclo->cierre_automatico,
                'cerrada_por' => $ciclo->cerradaPor,
            ],
            'usuario' => $ciclo->caja->user,
            'agencia' => $ciclo->caja->agencia,
            'billetajes' => $billetajes->map(fn (CajaMovimiento $m): array => [
                ...$this->movimiento($m),
                'billetaje_id' => $m->billetaje_id,
                'motivo' => $m->billetaje?->motivo,
                'enviado_por' => $m->billetaje?->solicitadoPor,
            ])->values(),
            'pagos_por_medio' => $pagos,
            'totales_por_medio' => $pagos->map(fn (Collection $items): string => $this->total($items)),
            'ingresos' => $ingresos->map(fn (CajaMovimiento $m): array => $this->movimiento($m))->values(),
            'desembolsos' => $desembolsos->map(fn (CajaMovimiento $m): array => [
                ...$this->movimiento($m),
                'credito_id' => $m->credito_id,
                'credito_codigo' => $m->credito?->codigo,
                'cliente' => $m->credito?->cliente,
            ])->values(),
            'gastos' => $gastosMapeados,
            'gastos_por_concepto' => $gastosMapeados->groupBy(fn (array $m): string => mb_strtolower(trim($m['concepto'] ?: 'Sin concepto')))
                ->map(fn (Collection $items): array => [
                    'concepto' => $items->first()['concepto'] ?: 'Sin concepto',
                    'cantidad' => $items->count(),
                    'total' => $this->total($items),
                    'items' => $items->values(),
                ])->values(),
            'resumen' => [
                'saldo_apertura' => $ciclo->saldo_apertura,
                'total_billetaje' => $this->total($billetajes),
                'total_cobrado' => $this->total($cobros, 'monto_pagado'),
                'total_ingresos' => $this->total($ingresos),
                'total_desembolsos' => $this->total($desembolsos),
                'total_gastos' => $this->total($gastos),
                'saldo_total' => $saldoTotal,
                'saldo_efectivo' => $saldoEfectivo,
                'monto_contado' => $ciclo->saldo_arqueo_cierre,
                'diferencia' => $diferencia,
                'faltante' => $faltante,
                'sobrante' => $sobrante,
                'neto' => bcadd($saldoTotal, $diferencia ?? '0', 2),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function movimiento(CajaMovimiento $movimiento): array
    {
        return [
            'id' => $movimiento->id,
            'fecha' => $movimiento->created_at,
            'hora' => $movimiento->created_at->timezone('America/Lima')->format('H:i'),
            'concepto' => $movimiento->concepto,
            'concepto_id' => $movimiento->concepto_id,
            'monto' => $movimiento->monto,
            'medio' => $movimiento->medio,
            'canal' => $movimiento->canal,
            'observacion' => $movimiento->descripcion,
            'serie' => $movimiento->serie,
            'numero' => $movimiento->numero,
            'comprobante_url' => $movimiento->fotos->firstWhere('tipo', 'comprobante')?->url,
        ];
    }

    /** @return array{estado_credito: ?string, dias_atraso: ?int} */
    private function estadoCredito(Cobro $cobro, CarbonInterface $fechaCorte, CarbonInterface $fechaReporte): array
    {
        $credito = $cobro->credito;
        if ($credito === null) {
            return ['estado_credito' => null, 'dias_atraso' => null];
        }

        // No usar el estado actual: una liquidacion posterior no debe alterar un cierre anterior.
        $liquidado = $credito->cobros->contains(fn (Cobro $pago): bool => $pago->created_at->lte($fechaCorte))
            || ($credito->cuotas->isNotEmpty() && $credito->cuotas->every(fn ($cuota): bool => $cuota->pagada_at !== null && $cuota->pagada_at->lte($fechaCorte)));
        $dias = $credito->fecha_vencimiento === null ? null
            : max(0, (int) $credito->fecha_vencimiento->diffInDays($fechaReporte->copy()->startOfDay(), false));

        $estado = match (true) {
            $liquidado => 'liquidado',
            $dias === null => null,
            $dias === 0 => 'al_dia',
            $dias <= 3 => 'atraso_leve',
            $dias <= 15 => 'atraso',
            default => 'mora',
        };

        return ['estado_credito' => $estado, 'dias_atraso' => $dias];
    }

    private function total(Collection $items, string $campo = 'monto'): string
    {
        return $items->reduce(fn (string $total, mixed $item): string => bcadd($total, (string) data_get($item, $campo, '0'), 2), '0.00');
    }
}
