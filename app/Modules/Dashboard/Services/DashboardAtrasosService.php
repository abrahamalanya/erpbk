<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Reportes\Tipos\AtrasoVencimiento;
use App\Modules\Reportes\Tipos\FranjaAtraso;
use App\Modules\Usuario\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Las dos líneas de tiempo del panel inicial, construidas sobre el mismo
 * semáforo de atraso del crédito completo que ya usa el reporte de atrasos:
 * cuántos créditos hay en cada franja de riesgo y cuánta plata arrastra cada
 * una.
 *
 * Cuenta CRÉDITOS, no clientes, a propósito: es la misma unidad de fila que el
 * reporte, así que el número del panel se reconcilia contra el reporte sin
 * cambiar de unidad. Un cliente con dos créditos aparece dos veces, que es lo
 * que pregunta "¿cuántos créditos tengo en rojo?".
 *
 * "Cobrado" significa cobrado HOY (un cobro con estado 'registrado' de la fecha
 * en curso), que es lo mismo que mide DashboardService::resumenDelDia(): las dos
 * líneas son el avance de la ronda de cobranza de hoy sobre la cartera que
 * arrastra mora. No es "alguna vez pagó" — ese número sería alto desde el primer
 * día y no se movería.
 *
 * El monto total de cada franja es el capital + interés financiado (la suma de
 * cuotas.monto_total), que es la columna Total del reporte, NO el saldo: el
 * gráfico responde "¿de cuánto es la cartera en mora?", no "¿cuánto me deben?".
 */
final class DashboardAtrasosService
{
    public function __construct(private readonly CreditoHierarchyService $creditos) {}

    /**
     * Payload de las dos líneas de tiempo, ya con porcentajes.
     *
     * @return array<string, mixed>
     */
    public function lineaDeTiempo(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipoCredito): array
    {
        $hoy = now()->startOfDay();

        $franjas = $this->franjasVacias();

        foreach ($this->creditosDelPanel($actor, $agenciaId, $asesorId, $tipoCredito, $hoy) as $credito) {
            $dias = AtrasoVencimiento::diasDeAtraso($credito->fecha_vencimiento, $hoy);
            $franja = FranjaAtraso::desde(AtrasoVencimiento::desdeDias($dias));

            $franjas[$franja->value]['creditos']++;
            $franjas[$franja->value]['monto_total'] += (float) $credito->cuotas->sum('monto_total');
            $franjas[$franja->value]['monto_cobrado'] += (float) $credito->cobros->sum('monto_pagado');

            // Importe > 0 en vez de "tiene cobros": un cobro registrado de monto
            // 0 no es avance de cobranza y no debe llenar la barra.
            if ($credito->cobros->sum('monto_pagado') > 0) {
                $franjas[$franja->value]['cobrados']++;
            }
        }

        return $this->armarPayload($hoy->toDateString(), $franjas);
    }

    /**
     * @return Collection<int, Credito>
     */
    private function creditosDelPanel(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipoCredito, CarbonInterface $hoy): Collection
    {
        return $this->creditos
            ->visibleQuery(Credito::query(), $actor)
            // Mismos estados que el reporte: lo ya cancelado o liquidado no
            // arrastra mora.
            ->whereIn('estado', ['activo', 'vencido'])
            ->when($agenciaId, fn (Builder $q) => $q->where('agencia_id', $agenciaId))
            ->when($tipoCredito, fn (Builder $q) => $q->where('tipo_credito', $tipoCredito))
            // Por el asesor del cliente, que es la columna Asesor del reporte.
            ->when($asesorId, fn (Builder $q) => $q->whereHas(
                'cliente',
                fn (Builder $c) => $c->where('asesor_id', $asesorId)
            ))
            ->with([
                'cuotas:id,credito_id,monto_total',
                // Sin tipo en la firma: dentro de with() el $q es la relación
                // HasMany, no un Builder (los eager loads no pasan por where()).
                'cobros' => fn ($q) => $q
                    ->where('estado', 'registrado')
                    ->whereDate('created_at', $hoy)
                    ->select(['id', 'credito_id', 'monto_pagado']),
            ])
            ->get(['id', 'fecha_vencimiento']);
    }

    /**
     * Esqueleto con las cuatro franjas ya presentes (en cero) para que el
     * frontend siempre reciba los cuatro tramos y pueda pintar la barra completa
     * aunque una franja no tenga nada.
     *
     * @return array<string, array{creditos: int, cobrados: int, monto_total: float, monto_cobrado: float}>
     */
    private function franjasVacias(): array
    {
        $vacias = [];

        foreach (FranjaAtraso::ordenadas() as $franja) {
            $vacias[$franja->value] = ['creditos' => 0, 'cobrados' => 0, 'monto_total' => 0.0, 'monto_cobrado' => 0.0];
        }

        return $vacias;
    }

    /**
     * @param  array<string, array{creditos: int, cobrados: int, monto_total: float, monto_cobrado: float}>  $franjas
     * @return array<string, mixed>
     */
    private function armarPayload(string $fecha, array $franjas): array
    {
        $totalCreditos = array_sum(array_column($franjas, 'creditos'));
        $totalCobrados = array_sum(array_column($franjas, 'cobrados'));
        $totalMonto = array_sum(array_column($franjas, 'monto_total'));
        $totalCobrado = array_sum(array_column($franjas, 'monto_cobrado'));

        $tramos = [];

        foreach (FranjaAtraso::ordenadas() as $franja) {
            $valores = $franjas[$franja->value];

            $tramos[] = [
                'key' => $franja->value,
                'etiqueta' => $franja->etiqueta(),
                'rango' => $franja->rango(),
                'color' => $franja->color(),
                // Qué categorías del reporte caen acá, para que el frontend
                // pueda armar la leyenda sin hardcodearla.
                'categorias' => array_map(fn (AtrasoVencimiento $c): string => $c->etiqueta(), $franja->categorias()),

                // Línea de tiempo 1: cantidad de créditos.
                'creditos' => $valores['creditos'],
                'participacion' => $this->porcentaje($valores['creditos'], $totalCreditos),
                'cobrados' => $valores['cobrados'],
                'porcentaje_cobrado' => $this->porcentaje($valores['cobrados'], $valores['creditos']),

                // Línea de tiempo 2: dinero (capital + interés).
                'monto_total' => round($valores['monto_total'], 2),
                'participacion_monto' => $this->porcentaje($valores['monto_total'], $totalMonto),
                'monto_cobrado' => round($valores['monto_cobrado'], 2),
                'porcentaje_cobrado_monto' => $this->porcentaje($valores['monto_cobrado'], $valores['monto_total']),
            ];
        }

        return [
            'fecha' => $fecha,
            'total_creditos' => $totalCreditos,
            'total_monto' => round($totalMonto, 2),
            'cobrados' => $totalCobrados,
            'monto_cobrado' => round($totalCobrado, 2),

            // Porcentaje de avance de la barra. Null cuando no hay nada que
            // cobrar: un 0% ahí sería un dato falso (no se cobró nada porque
            // no hay cartera, no porque falló la cobranza).
            'porcentaje_cobrado' => $this->porcentaje($totalCobrados, $totalCreditos),
            'porcentaje_cobrado_monto' => $this->porcentaje($totalCobrado, $totalMonto),

            'tramos' => $tramos,
        ];
    }

    /**
     * Porcentaje redondeado a 2 decimales, o null cuando el denominador es 0.
     *
     * @param  int|float  $parte
     */
    private function porcentaje($parte, float $total): ?float
    {
        if ($total <= 0) {
            return null;
        }

        return round($parte / $total * 100, 2);
    }
}
