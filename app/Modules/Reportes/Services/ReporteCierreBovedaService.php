<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\BovedaCiclo;
use App\Modules\Caja\Models\BovedaMovimiento;

final class ReporteCierreBovedaService
{
    /** @return array<string, mixed> */
    public function reporte(BovedaCiclo $ciclo): array
    {
        $ciclo->load(['boveda.agencia', 'abiertaPor', 'cerradaPor']);

        $movimientos = $ciclo->movimientos()
            ->with(['billetaje.asesor', 'registradoPor'])
            ->orderBy('created_at')->orderBy('id')->get();

        $ingresos = $movimientos->where('tipo', 'ingreso');
        $billetajes = $movimientos->filter(fn (BovedaMovimiento $m): bool => $m->tipo === 'egreso' && $m->billetaje_id !== null);
        $egresos = $movimientos->filter(fn (BovedaMovimiento $m): bool => $m->tipo === 'egreso' && $m->billetaje_id === null);

        $saldoInicial = (string) $ciclo->saldo_apertura;
        $totalIngresos = bcadd($saldoInicial, (string) $ingresos->sum('monto'), 2);
        $totalEgresos = bcadd((string) $billetajes->sum('monto'), (string) $egresos->sum('monto'), 2);
        [$faltante, $sobrante] = $this->faltanteSobrante($ciclo->diferencia);

        return [
            'ciclo' => [
                'id' => $ciclo->id,
                'boveda_id' => $ciclo->boveda_id,
                'fecha' => $ciclo->fecha->toDateString(),
                'estado' => $ciclo->estado,
                'abierta_at' => $ciclo->abierta_at,
                'cerrada_at' => $ciclo->cerrada_at,
            ],
            'boveda' => $ciclo->boveda,
            'abierta_por' => $ciclo->abiertaPor,
            'cerrada_por' => $ciclo->cerradaPor,
            'ingresos' => $ingresos->map(fn (BovedaMovimiento $m): array => $this->movimiento($m))->values(),
            'billetajes_entregados' => $billetajes->map(fn (BovedaMovimiento $m): array => [
                ...$this->movimiento($m),
                'asesor' => $m->billetaje?->asesor,
            ])->values(),
            'egresos' => $egresos->map(fn (BovedaMovimiento $m): array => $this->movimiento($m))->values(),
            'resumen' => [
                'saldo_apertura' => $saldoInicial,
                'total_ingresos' => $totalIngresos,
                'total_billetaje_entregado' => $this->formatMonto((string) $billetajes->sum('monto')),
                'total_egresos' => $totalEgresos,
                'faltante' => $faltante,
                'sobrante' => $sobrante,
                'saldo_arqueo_cierre' => $ciclo->saldo_arqueo_cierre,
                'saldo_cierre' => bcadd(bcsub($totalIngresos, $totalEgresos, 2), bcsub($sobrante, $faltante, 2), 2),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function movimiento(BovedaMovimiento $m): array
    {
        return [
            'id' => $m->id,
            'fecha' => $m->created_at,
            'hora' => $m->created_at->timezone('America/Lima')->format('H:i'),
            'concepto' => $m->concepto,
            'origen' => $m->origen,
            'monto' => (string) $m->monto,
            'registrado_por' => $m->registradoPor,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function faltanteSobrante(string|int|float|null $diferencia): array
    {
        if ($diferencia === null) {
            return ['0.00', '0.00'];
        }

        $diferencia = (string) $diferencia;

        return bccomp($diferencia, '0', 2) < 0
            ? [bcsub('0', $diferencia, 2), '0.00']
            : ['0.00', $diferencia];
    }

    private function formatMonto(string|int|float|null $monto): string
    {
        return bcadd((string) ($monto ?? '0'), '0', 2);
    }
}
