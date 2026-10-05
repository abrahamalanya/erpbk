<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Reportes\Tipos\AtrasoCuota;
use App\Modules\Reportes\Tipos\AtrasoVencimiento;
use App\Modules\Usuario\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ReporteAtrasosService
{
    public function __construct(private readonly CreditoHierarchyService $hierarchy) {}

    /**
     * Reporte diario de atrasos: una fila por crédito activo o vencido con
     * dos medidores de mora que NO son el mismo número y no deben leerse
     * como si lo fueran.
     *
     * - atraso_venc_dias: días desde el vencimiento del CRÉDITO COMPLETO
     *   (creditos_prendarios.fecha_vencimiento vs hoy). Marca cuándo se cae
     *   la totalidad de la deuda.
     * - dias_atraso: días desde la CUOTA vencida impaga más antigua. Marca
     *   desde cuándo el cliente dejó de pagar, que suele ser mucho antes de
     *   que venza el crédito completo (un diario a 30 ya arrastra mora con la
     *   mitad del plazo corriendo).
     *
     * Entra todo crédito activo/vencido, tenga mora o esté al día: lo al día
     * sale sin bolita (atraso_venc_dias/dias_atraso en 0, categorías null)
     * pero con rank 5, que es lo que lo deja en su escalón y no arrastra la
     * tabla.
     *
     * El orden es por rank de gravedad ASCENDENTE (1 = Pérdida, la mora
     * activa más grave) con el Castigado clavado al final — ver
     * AtrasoVencimiento::rank().
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function atrasos(User $actor): Collection
    {
        $hoy = now()->startOfDay();

        $creditos = $this->hierarchy
            ->visibleQuery(Credito::query(), $actor)
            ->whereIn('estado', ['activo', 'vencido'])
            ->with(['cliente.asesor'])
            ->get()
            ->load([
                'cuotas',
                'cobros' => fn ($q) => $q->where('estado', 'registrado')->latest('created_at'),
            ]);

        return $creditos
            ->map(fn (Credito $credito): array => $this->mapearFila($credito, $hoy))
            ->sort(fn (array $a, array $b): int => $this->compararFilas($a, $b))
            ->values();
    }

    /**
     * Orden del reporte: rank de gravedad ascendente (1 = Pérdida, la mora
     * activa más grave; 6 = Castigado, siempre último), y dentro del mismo
     * escalón el que más días de mora lleva. El código de crédito solo
     * desempata para que dos filas iguales salgan siempre en el mismo orden
     * entre corridas.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compararFilas(array $a, array $b): int
    {
        return [$a['atraso_venc_rank'], -$a['dias_atraso'], (string) $a['credito_codigo']]
            <=> [$b['atraso_venc_rank'], -$b['dias_atraso'], (string) $b['credito_codigo']];
    }

    /**
     * Días de atraso contra el vencimiento del crédito completo. Delega en la
     * enum para que el panel inicial mida exactamente lo mismo que este
     * reporte y los dos no puedan pintar el mismo crédito de colores distintos.
     */
    private function atrasoVencDias(Credito $credito, CarbonInterface $hoy): int
    {
        return AtrasoVencimiento::diasDeAtraso($credito->fecha_vencimiento, $hoy);
    }

    /**
     * Cuotas VENCIDAS e impagas, de la más antigua a la más reciente.
     *
     * Vacía cuando no hay ninguna: el crédito va al día en este medidor
     * aunque su vencimiento completo ya haya pasado (típico del interés
     * compuesto y del prendario de pago único al final).
     *
     * Estrictamente anterior a hoy a propósito: una cuota que vence HOY no
     * está atrasada todavía, se cobra hoy. Por eso dias_atraso === 0 siempre
     * viene con cero cuotas atrasadas y sin bolita, que es la regla del
     * semáforo.
     *
     * @return Collection<int, CuotaCredito>
     */
    private function cuotasVencidasImpagas(Credito $credito, CarbonInterface $hoy): Collection
    {
        return $credito->cuotas
            ->filter(fn (CuotaCredito $cuota): bool => $cuota->pagada_at === null && $cuota->fecha_vencimiento->lt($hoy))
            ->sortBy(fn (CuotaCredito $cuota): int => $cuota->fecha_vencimiento->getTimestamp())
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapearFila(Credito $credito, CarbonInterface $hoy): array
    {
        $atrasoVencDias = $this->atrasoVencDias($credito, $hoy);
        $categoriaVenc = AtrasoVencimiento::desdeDias($atrasoVencDias);

        $cuotasAtrasadas = $this->cuotasVencidasImpagas($credito, $hoy);
        $diasAtraso = $cuotasAtrasadas->isEmpty()
            ? 0
            : (int) $cuotasAtrasadas->first()->fecha_vencimiento->diffInDays($hoy);
        $categoriaCuota = AtrasoCuota::desdeDias($diasAtraso);

        $total = (float) $credito->cuotas->sum('monto_total');
        $abonado = (float) $credito->cuotas->sum('monto_abonado');

        // Los cobros vienen ordenados por created_at desc y filtrando los
        // anulados: el primero es el abono vigente más reciente.
        $ultimoCobro = $credito->cobros->first();

        return [
            'credito_id' => $credito->id,
            'credito_codigo' => $credito->codigo,
            'tipo_credito' => $credito->tipo_credito,
            'estado' => $credito->estado,
            'cliente' => $credito->cliente,
            'asesor' => $credito->cliente?->asesor,
            'fecha_desembolso' => $credito->fecha_desembolso?->toDateString(),
            'fecha_vencimiento' => $credito->fecha_vencimiento?->toDateString(),

            'atraso_venc_dias' => $atrasoVencDias,
            'atraso_venc_categoria' => $categoriaVenc?->etiqueta(),
            'atraso_venc_color' => $categoriaVenc?->color(),
            'atraso_venc_rank' => $categoriaVenc?->rank() ?? AtrasoVencimiento::rankSinClasificar(),

            'dias_atraso' => $diasAtraso,
            'cuotas_atrasadas' => $cuotasAtrasadas->count(),
            'atraso_cuota_nivel' => $categoriaCuota?->value,
            'atraso_cuota_color' => $categoriaCuota?->color(),

            'ultimo_abono' => $ultimoCobro?->created_at?->toDateString(),
            'ultimo_abono_monto' => $ultimoCobro?->monto_pagado,

            'tipo_cuota' => $credito->tipo_cuota,
            'monto_prestamo' => (float) $credito->monto_prestamo,
            'interes_prestamo' => (float) $credito->interes,
            'total' => $total,
            'saldo' => max($total - $abonado, 0),
        ];
    }
}
