<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Caja\Models\BovedaCiclo;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReporteHistorialBovedaService
{
    /**
     * @var array<string, string>
     */
    private const ROLES_LABEL = [
        'administrador_general' => 'Admin. general',
        'administrador_agencia' => 'Admin. agencia',
        'secretaria' => 'Secretaria',
        'supervisor' => 'Supervisor',
        'asesor' => 'Asesor',
    ];

    /**
     * Una fila por cada BovedaCiclo y CajaCiclo de $fecha (un solo día),
     * fusionados en una sola lista uniforme: Historial de Bóveda muestra
     * TODAS las bóvedas y TODAS las cajas del sistema, no solo las abiertas.
     * Bóvedas primero (principal antes que agencia), luego cajas — ambos
     * grupos ordenados por agencia y luego por usuario.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listar(string $fecha, ?string $estado, ?int $agenciaId): Collection
    {
        $bovedas = BovedaCiclo::query()
            ->whereDate('fecha', $fecha)
            ->when($estado, fn (Builder $q) => $q->where('estado', $estado))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('boveda', fn (Builder $b) => $b->where('agencia_id', $agenciaId)))
            ->with(['boveda.agencia', 'abiertaPor'])
            ->withSum(['movimientos as ingresos' => fn (Builder $q) => $q->where('tipo', 'ingreso')], 'monto')
            ->withSum(['movimientos as egresos' => fn (Builder $q) => $q->where('tipo', 'egreso')->whereNull('billetaje_id')], 'monto')
            ->withSum(['movimientos as desembolsos' => fn (Builder $q) => $q->where('tipo', 'egreso')->whereNotNull('billetaje_id')], 'monto')
            ->get()
            ->map(fn (BovedaCiclo $ciclo): array => $this->filaBoveda($ciclo));

        $cajas = CajaCiclo::query()
            ->whereDate('fecha', $fecha)
            ->when($estado, fn (Builder $q) => $q->where('estado', $estado))
            ->when($agenciaId, fn (Builder $q) => $q->whereHas('caja', fn (Builder $c) => $c->where('agencia_id', $agenciaId)))
            ->with(['caja.user.roles', 'caja.agencia'])
            ->withSum(['movimientos as billetaje' => fn (Builder $q) => $q->billetajes()], 'monto')
            ->withSum(['cobros as abonos' => fn (Builder $q) => $q->where('estado', '!=', 'anulado')], 'monto_pagado')
            ->withSum(['movimientos as ingresos' => fn (Builder $q) => $q->ingresosManuales()], 'monto')
            ->withSum(['movimientos as ventas' => fn (Builder $q) => $q->ventas()], 'monto')
            ->withSum(['movimientos as egresos' => fn (Builder $q) => $q->egresosManuales()], 'monto')
            ->withSum(['movimientos as desembolsos' => fn (Builder $q) => $q->desembolsos()], 'monto')
            ->get()
            ->map(fn (CajaCiclo $ciclo): array => $this->filaCaja($ciclo));

        return $bovedas
            ->sortBy(fn (array $fila): string => $fila['boveda']->tipo === 'principal' ? '' : $fila['agencia'])
            ->concat($cajas->sortBy(fn (array $fila): string => $fila['agencia'].'-'.($fila['usuario']?->nombre ?? '')))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function filaBoveda(BovedaCiclo $ciclo): array
    {
        $saldoInicial = (string) $ciclo->saldo_apertura;
        $ingresos = $this->formatMonto($ciclo->getAttribute('ingresos'));
        $egresos = $this->formatMonto($ciclo->getAttribute('egresos'));
        $desembolsos = $this->formatMonto($ciclo->getAttribute('desembolsos'));
        $billetaje = '0.00';
        $abonos = '0.00';
        $ventas = '0.00';

        $totalIngresos = bcadd($saldoInicial, bcadd($billetaje, bcadd($abonos, bcadd($ingresos, $ventas, 2), 2), 2), 2);
        $totalEgresos = bcadd($desembolsos, $egresos, 2);
        [$faltante, $sobrante] = $this->faltanteSobrante($ciclo->diferencia);

        return [
            'fila_tipo' => 'boveda',
            'id' => $ciclo->id,
            'boveda' => $ciclo->boveda,
            'fecha' => $ciclo->fecha->toDateString(),
            'tipo' => ucfirst($ciclo->boveda->tipo),
            'estado' => $ciclo->estado,
            'agencia' => $ciclo->boveda->agencia?->nombre ?? 'Principal',
            'usuario' => $ciclo->abiertaPor,
            'saldo_inicial' => $saldoInicial,
            'billetaje' => $billetaje,
            'abonos' => $abonos,
            'ingresos' => $ingresos,
            'ventas' => $ventas,
            'egresos' => $egresos,
            'desembolsos' => $desembolsos,
            'total_ingresos' => $totalIngresos,
            'total_egresos' => $totalEgresos,
            'faltante' => $faltante,
            'sobrante' => $sobrante,
            'saldo_cierre' => bcadd(bcsub($totalIngresos, $totalEgresos, 2), bcsub($sobrante, $faltante, 2), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filaCaja(CajaCiclo $ciclo): array
    {
        $saldoInicial = (string) $ciclo->saldo_apertura;
        $billetaje = $this->formatMonto($ciclo->getAttribute('billetaje'));
        $abonos = $this->formatMonto($ciclo->getAttribute('abonos'));
        $ingresos = $this->formatMonto($ciclo->getAttribute('ingresos'));
        $ventas = $this->formatMonto($ciclo->getAttribute('ventas'));
        $egresos = $this->formatMonto($ciclo->getAttribute('egresos'));
        $desembolsos = $this->formatMonto($ciclo->getAttribute('desembolsos'));

        $totalIngresos = bcadd($saldoInicial, bcadd($billetaje, bcadd($abonos, bcadd($ingresos, $ventas, 2), 2), 2), 2);
        $totalEgresos = bcadd($desembolsos, $egresos, 2);
        [$faltante, $sobrante] = $this->faltanteSobrante($ciclo->diferencia);

        return [
            'fila_tipo' => 'caja',
            'id' => $ciclo->id,
            'caja' => $ciclo->caja,
            'fecha' => $ciclo->fecha->toDateString(),
            'tipo' => $this->rolLabel($ciclo->caja),
            'estado' => $ciclo->estado,
            'agencia' => $ciclo->caja->agencia?->nombre ?? 'Principal',
            'usuario' => $ciclo->caja->user,
            'saldo_inicial' => $saldoInicial,
            'billetaje' => $billetaje,
            'abonos' => $abonos,
            'ingresos' => $ingresos,
            'ventas' => $ventas,
            'egresos' => $egresos,
            'desembolsos' => $desembolsos,
            'total_ingresos' => $totalIngresos,
            'total_egresos' => $totalEgresos,
            'faltante' => $faltante,
            'sobrante' => $sobrante,
            'saldo_cierre' => bcadd(bcsub($totalIngresos, $totalEgresos, 2), bcsub($sobrante, $faltante, 2), 2),
        ];
    }

    private function rolLabel(Caja $caja): string
    {
        $rol = $caja->user?->roles?->first()?->name;

        if ($rol === null) {
            return '—';
        }

        return self::ROLES_LABEL[$rol] ?? ucfirst($rol);
    }

    /**
     * @return array{0: string, 1: string} [faltante, sobrante], ambos "0.00"
     *                                     mientras el ciclo sigue abierto (diferencia null).
     */
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

    /**
     * withSum() aggregates come back as a raw number (or null when there are
     * no matching rows) — normalize to the same "0.00"-string shape as the
     * model's own decimal:2-cast columns.
     */
    private function formatMonto(string|int|float|null $monto): string
    {
        return bcadd((string) ($monto ?? '0'), '0', 2);
    }
}
