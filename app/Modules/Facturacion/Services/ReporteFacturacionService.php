<?php

namespace App\Modules\Facturacion\Services;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reportes del módulo de facturación. Lo mínimo que pidió el cliente en
 * APUNTES.md:
 *
 *  - "CONTADOR TOTAL DE CUANTO SE HA EMITIDO HASTA LA FECHA" → resumen()
 *  - "REPORTE DE LAS BOLETAS EMITIDAS" → emitidas()
 *  - "ALERTA PARA EMITIR FACTURACION DIARIO" → pendientesDelDia() (créditos a
 *    los que les falta facturaron, cobrando su interés del día)
 *
 * Todo va acotado a la empresa del actor por el global scope, salvo sistemas
 * que puede filtrar explícitamente por empresa.
 */
final class ReporteFacturacionService
{
    /**
     * Contadores del rango: cuántos se emitieron, por tipo, por estado, y el
     * monto total. Es lo que "cuánto se ha emitido hasta la fecha" pide.
     *
     * @return array<string, mixed>
     */
    public function resumen(int $empresaId, ?CarbonImmutable $desde, ?CarbonImmutable $hasta, ?int $agenciaId = null): array
    {
        $base = $this->queryEmitidos($empresaId, $agenciaId)
            ->when($desde !== null, fn ($q) => $q->where('fecha_emision', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha_emision', '<=', $hasta));

        return [
            'desde' => $desde?->toDateString(),
            'hasta' => $hasta?->toDateString(),
            'cantidad_total' => (clone $base)->count(),
            'monto_total' => (clone $base)->sum('total'),
            'por_tipo' => (clone $base)->selectRaw('tipo_documento, COUNT(*) as cantidad, SUM(total) as monto')
                ->groupBy('tipo_documento')
                ->get()
                ->mapWithKeys(fn ($row): array => [
                    $row->tipo_documento => ['cantidad' => (int) $row->cantidad, 'monto' => (float) $row->monto],
                ])
                ->all(),
            'por_estado' => (clone $base)->selectRaw('estado, COUNT(*) as cantidad')
                ->groupBy('estado')
                ->pluck('cantidad', 'estado')
                ->all(),
        ];
    }

    /**
     * El detalle de comprobantes emitidos en el rango, listo para el reporte
     * y el Excel.
     *
     * @return Collection<int, Comprobante>
     */
    public function emitidas(int $empresaId, ?CarbonImmutable $desde, ?CarbonImmutable $hasta, ?int $agenciaId = null): Collection
    {
        return $this->queryEmitidos($empresaId, $agenciaId)
            ->when($desde !== null, fn ($q) => $q->where('fecha_emision', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha_emision', '<=', $hasta))
            ->orderBy('fecha_emision')
            ->orderBy('correlativo')
            ->get();
    }

    /**
     * Por qué día se emitió cuánto — para la gráfica diaria.
     *
     * @return Collection<int, array{fecha: string, cantidad: int, monto: float}>
     */
    public function porDia(int $empresaId, ?CarbonImmutable $desde, ?CarbonImmutable $hasta, ?int $agenciaId = null): Collection
    {
        return $this->queryEmitidos($empresaId, $agenciaId)
            ->when($desde !== null, fn ($q) => $q->where('fecha_emision', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha_emision', '<=', $hasta))
            ->selectRaw('DATE(fecha_emision) as fecha, COUNT(*) as cantidad, SUM(total) as monto')
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->map(fn ($row): array => ['fecha' => $row->fecha, 'cantidad' => (int) $row->cantidad, 'monto' => (float) $row->monto])
            ->values();
    }

    /**
     * Conteos por estado.
     *
     * @return array<string, int>
     */
    public function porEstado(int $empresaId, ?CarbonImmutable $desde, ?CarbonImmutable $hasta, ?int $agenciaId = null): array
    {
        $base = $this->queryEmitidos($empresaId, $agenciaId)
            ->when($desde !== null, fn ($q) => $q->where('fecha_emision', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha_emision', '<=', $hasta));

        $estados = EstadoComprobante::cases();
        $result = [];

        foreach ($estados as $estado) {
            $count = (clone $base)->where('estado', $estado->value)->count();
            $result[$estado->value] = $count;
        }

        return $result;
    }

    /**
     * Conteos por serie.
     *
     * @return Collection<int, array{serie: string, tipo: string, cantidad: int, monto: float, ultimo_correlativo: int}>
     */
    public function porSerie(int $empresaId, ?CarbonImmutable $desde, ?CarbonImmutable $hasta, ?int $agenciaId = null): Collection
    {
        return $this->queryEmitidos($empresaId, $agenciaId)
            ->when($desde !== null, fn ($q) => $q->where('fecha_emision', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha_emision', '<=', $hasta))
            ->selectRaw('serie, tipo_documento, COUNT(*) as cantidad, SUM(total) as monto, MAX(correlativo) as ultimo_correlativo')
            ->groupBy('serie', 'tipo_documento')
            ->orderBy('tipo_documento')
            ->orderBy('serie')
            ->get()
            ->map(fn ($row): array => [
                'serie' => $row->serie,
                'tipo' => $row->tipo_documento,
                'tipo_nombre' => TipoComprobante::tryFrom($row->tipo_documento)?->denominacion() ?? $row->tipo_documento,
                'cantidad' => (int) $row->cantidad,
                'monto' => (float) $row->monto,
                'ultimo_correlativo' => (int) $row->ultimo_correlativo,
            ])
            ->values();
    }

    /**
     * Reporte agrupado por cliente.
     *
     * @return Collection<int, array{cliente_id: int, cliente_nombre: string, documento: string, cantidad: int, monto: float}>
     */
    public function porCliente(int $empresaId, ?CarbonImmutable $desde, ?CarbonImmutable $hasta, ?int $agenciaId = null): Collection
    {
        return $this->queryEmitidos($empresaId, $agenciaId)
            ->when($desde !== null, fn ($q) => $q->where('fecha_emision', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha_emision', '<=', $hasta))
            ->selectRaw('cliente_id, receptor_nombre, receptor_numero_documento, COUNT(*) as cantidad, SUM(total) as monto')
            ->groupBy('cliente_id', 'receptor_nombre', 'receptor_numero_documento')
            ->orderByDesc('monto')
            ->get()
            ->map(fn ($row): array => [
                'cliente_id' => (int) $row->cliente_id,
                'cliente_nombre' => $row->receptor_nombre,
                'documento' => $row->receptor_numero_documento,
                'cantidad' => (int) $row->cantidad,
                'monto' => (float) $row->monto,
            ])
            ->values();
    }

    /**
     * Comprobantes anulados (local o baja comunicada).
     *
     * @return Collection<int, Comprobante>
     */
    public function anulados(int $empresaId, ?CarbonImmutable $desde, ?CarbonImmutable $hasta, ?int $agenciaId = null): Collection
    {
        return $this->queryEmitidos($empresaId, $agenciaId)
            ->when($desde !== null, fn ($q) => $q->where('fecha_emision', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha_emision', '<=', $hasta))
            ->whereIn('estado', [EstadoComprobante::Anulado->value])
            ->orderByDesc('anulado_at')
            ->get();
    }

    /**
     * Notas de crédito emitidas.
     *
     * @return Collection<int, Comprobante>
     */
    public function notasCredito(int $empresaId, ?CarbonImmutable $desde, ?CarbonImmutable $hasta, ?int $agenciaId = null): Collection
    {
        return $this->queryEmitidos($empresaId, $agenciaId)
            ->when($desde !== null, fn ($q) => $q->where('fecha_emision', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('fecha_emision', '<=', $hasta))
            ->where('tipo_documento', TipoComprobante::NotaCredito->value)
            ->orderByDesc('fecha_emision')
            ->orderByDesc('correlativo')
            ->get();
    }

    /**
     * Dashboard: contadores para hoy, mes y totales.
     *
     * @return array<string, mixed>
     */
    public function dashboard(int $empresaId, ?int $agenciaId = null): array
    {
        $hoy = CarbonImmutable::today();
        $inicioMes = $hoy->startOfMonth();
        $finMes = $hoy->endOfMonth();

        // Base query
        $base = $this->queryEmitidos($empresaId, $agenciaId);

        // Hoy
        $hoyQuery = (clone $base)->whereDate('fecha_emision', $hoy);
        $hoyCount = $hoyQuery->count();
        $hoyMonto = $hoyQuery->sum('total');
        $hoyPorEstado = $hoyQuery->selectRaw('estado, COUNT(*) as cantidad')
            ->groupBy('estado')
            ->pluck('cantidad', 'estado')
            ->all();

        // Mes
        $mesQuery = (clone $base)->whereBetween('fecha_emision', [$inicioMes, $finMes]);
        $mesCount = $mesQuery->count();
        $mesMonto = $mesQuery->sum('total');
        $mesPorEstado = $mesQuery->selectRaw('estado, COUNT(*) as cantidad')
            ->groupBy('estado')
            ->pluck('cantidad', 'estado')
            ->all();

        // Totales históricos
        $totalCount = (clone $base)->count();
        $totalMonto = (clone $base)->sum('total');

        // Por tipo (histórico)
        $porTipo = (clone $base)->selectRaw('tipo_documento, COUNT(*) as cantidad, SUM(total) as monto')
            ->groupBy('tipo_documento')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                $row->tipo_documento => ['cantidad' => (int) $row->cantidad, 'monto' => (float) $row->monto],
            ])
            ->all();

        // Créditos pendientes de facturar (para la alerta diaria)
        $creditosPendientes = \App\Modules\Credito\Models\Credito::query()
            ->where('empresa_id', $empresaId)
            ->when($agenciaId !== null, fn ($q) => $q->where('agencia_id', $agenciaId))
            ->whereIn('estado', ['liquidado', 'liquidado_pendiente'])
            ->whereDoesntHave('comprobantes', fn ($query) => $query->where('origen_tipo', 'credito'))
            ->count();

        return [
            'hoy' => [
                'fecha' => $hoy->toDateString(),
                'cantidad' => $hoyCount,
                'monto' => (float) $hoyMonto,
                'por_estado' => $hoyPorEstado,
            ],
            'mes' => [
                'desde' => $inicioMes->toDateString(),
                'hasta' => $finMes->toDateString(),
                'cantidad' => $mesCount,
                'monto' => (float) $mesMonto,
                'por_estado' => $mesPorEstado,
            ],
            'historico' => [
                'cantidad_total' => $totalCount,
                'monto_total' => (float) $totalMonto,
                'por_tipo' => $porTipo,
            ],
            'creditos_pendientes_facturar' => $creditosPendientes,
        ];
    }

    private function queryEmitidos(int $empresaId, ?int $agenciaId)
    {
        return Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($agenciaId !== null, fn ($q) => $q->where('agencia_id', $agenciaId));
    }
}