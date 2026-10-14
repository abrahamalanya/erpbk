<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\PagoVenta;
use App\Modules\Venta\Models\Venta;
use Illuminate\Database\Eloquent\Builder;

/**
 * Totales del día para el panel inicial: cuánto entró por cobranzas y cuánto
 * salió por desembolsos, con los mismos filtros y la misma jerarquía de
 * visibilidad que los listados de Cobranzas, Créditos y Ventas — un asesor que
 * abre el dashboard ve su propia operación, no la de toda la empresa.
 *
 * "Cobranza" son los cobros sobre créditos (`cobros`) más los pagos de ventas
 * de tienda (`pagos_venta`), porque para el negocio es indistinto el dinero que
 * entra por una cuota de prendario o por una venta de contado. El filtro por
 * tipo reparte entre las dos fuentes: `ventas` lee pagos_venta y los demás
 * leen cobros vía su crédito.
 *
 * "Desembolso" son los egresos de caja que cancelan un crédito
 * (CajaMovimiento::desembolsos()), que es el único egreso que genera
 * CreditoService::desembolsar().
 *
 * "Egresos" son los egresos de caja MANUALES (CajaMovimiento::egresosManuales(),
 * tipo=egreso SIN credito_id) — gastos generales, deliberadamente distintos
 * de "desembolso" para no contar el mismo movimiento en las dos cards.
 *
 * "Vencidos" no es "del día": es la cartera vencida visible para el actor en
 * este momento (cantidad de créditos + su saldo pendiente), mismo cálculo de
 * saldo que ReporteAtrasosService.
 */
final class DashboardService
{
    /**
     * Créditos de garantías formales. El mismo juego de valores que usan las
     * requests de créditos y de rutas de cobranza.
     *
     * @var list<string>
     */
    public const TIPOS_CREDITO = ['prendario', 'vehicular', 'hipotecario', 'diario'];

    /** Ventas de tienda — no son créditos, pero también se cobran. */
    public const TIPO_VENTAS = 'ventas';

    /**
     * @var list<string>
     */
    public const TIPOS = [...self::TIPOS_CREDITO, self::TIPO_VENTAS];

    public function __construct(
        private readonly CreditoHierarchyService $creditos,
        private readonly CajaBovedaHierarchyService $cajas,
    ) {}

    /**
     * Resumen del día para el actor, acotado por los filtros opcionales.
     *
     * @return array<string, mixed>
     */
    public function resumenDelDia(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipoCredito): array
    {
        $hoy = now()->toDateString();

        $cobranza = $this->totalCobranzas($actor, $agenciaId, $asesorId, $tipoCredito, $hoy);
        $desembolso = $this->totalDesembolsos($actor, $agenciaId, $asesorId, $tipoCredito, $hoy);

        return [
            'fecha' => $hoy,
            'cobranza' => $cobranza,
            'desembolso' => $desembolso,
            // Lo que quedó en caja por la operación del día. Puede ser
            // negativo: es la diferencia, no un saldo de cuentas.
            'neto' => round($cobranza['total'] - $desembolso['total'], 2),
            'egresos' => $this->totalEgresos($actor, $agenciaId, $asesorId, $hoy),
            'vencidos' => $this->totalVencidos($actor, $agenciaId, $asesorId, $tipoCredito),
        ];
    }

    /**
     * @return array{total: float, cantidad: int}
     */
    private function totalCobranzas(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo, string $hoy): array
    {
        $total = 0.0;
        $cantidad = 0;

        // Créditos: skipped cuando el filtro es ventas (no son créditos).
        if ($tipo !== self::TIPO_VENTAS) {
            $cobros = Cobro::query()
                ->where('estado', 'registrado')
                ->whereDate('created_at', $hoy)
                ->whereHas('credito', function (Builder $q) use ($actor, $agenciaId, $tipo): void {
                    $this->creditos->visibleQuery($q, $actor);

                    if ($agenciaId) {
                        $q->where('agencia_id', $agenciaId);
                    }

                    if ($tipo) {
                        $q->where('tipo_credito', $tipo);
                    }
                });

            if ($asesorId) {
                $cobros->where('registrado_por', $asesorId);
            }

            [$suma, $conteo] = $this->sumar($cobros, 'monto_pagado');

            $total += $suma;
            $cantidad += $conteo;
        }

        // Ventas de tienda: solo entran si el filtro no está atado a un tipo de
        // crédito concreto; con un tipo de crédito elegido se leen solo cobros.
        if ($tipo === null || $tipo === self::TIPO_VENTAS) {
            $pagos = PagoVenta::query()
                ->whereNull('anulado_at')
                ->whereDate('created_at', $hoy)
                ->whereHas('venta', function (Builder $q) use ($actor, $agenciaId): void {
                    $this->acotarVentas($q, $actor);

                    if ($agenciaId) {
                        $q->where('agencia_id', $agenciaId);
                    }
                });

            if ($asesorId) {
                $pagos->where('registrado_por', $asesorId);
            }

            [$suma, $conteo] = $this->sumar($pagos, 'monto');

            $total += $suma;
            $cantidad += $conteo;
        }

        return ['total' => round($total, 2), 'cantidad' => $cantidad];
    }

    /**
     * @return array{total: float, cantidad: int}
     */
    private function totalDesembolsos(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo, string $hoy): array
    {
        // Las ventas de tienda no se financian con desembolso de crédito, así
        // que ese filtro no aplica acá: el total queda en cero.
        if ($tipo === self::TIPO_VENTAS) {
            return ['total' => 0.0, 'cantidad' => 0];
        }

        $query = CajaMovimiento::query()
            ->desembolsos()
            ->whereDate('created_at', $hoy)
            ->whereHas('credito', function (Builder $q) use ($actor, $agenciaId, $tipo): void {
                $this->creditos->visibleQuery($q, $actor);

                if ($agenciaId) {
                    $q->where('agencia_id', $agenciaId);
                }

                if ($tipo) {
                    $q->where('tipo_credito', $tipo);
                }
            });

        if ($asesorId) {
            $query->where('registrado_por', $asesorId);
        }

        [$suma, $conteo] = $this->sumar($query, 'monto');

        return ['total' => round($suma, 2), 'cantidad' => $conteo];
    }

    /**
     * Egresos manuales de caja del día (gastos generales) — deliberadamente
     * `egresosManuales()` (tipo=egreso SIN credito_id, ver
     * CajaMovimiento::scopeEgresosManuales()) y no todo tipo=egreso, para no
     * contar dos veces el mismo movimiento que ya suma `desembolso`
     * (CajaMovimiento::scopeDesembolsos() es tipo=egreso CON credito_id).
     * Mismo criterio de visibilidad de caja que ReporteIngresosEgresosService
     * (cajaCiclo.caja + CajaBovedaHierarchyService::cajasVisibles()), no el
     * de créditos: un egreso manual no tiene crédito del que colgarse.
     *
     * `tipo_credito` no aplica aquí (un gasto general no es de ningún tipo de
     * crédito) y se ignora a propósito; `asesor_id` sí filtra, igual que en
     * cobranza/desembolso, por quien registró el movimiento.
     *
     * @return array{total: float, cantidad: int}
     */
    private function totalEgresos(User $actor, ?int $agenciaId, ?int $asesorId, string $hoy): array
    {
        $query = CajaMovimiento::query()
            ->egresosManuales()
            ->whereDate('created_at', $hoy)
            ->whereHas('cajaCiclo.caja', function (Builder $q) use ($actor, $agenciaId): void {
                $this->cajas->cajasVisibles($q, $actor);

                if ($agenciaId) {
                    $q->where('agencia_id', $agenciaId);
                }
            });

        if ($asesorId) {
            $query->where('registrado_por', $asesorId);
        }

        [$suma, $conteo] = $this->sumar($query, 'monto');

        return ['total' => round($suma, 2), 'cantidad' => $conteo];
    }

    /**
     * Créditos vencidos visibles para el actor: cantidad y saldo pendiente
     * (misma fórmula que ReporteAtrasosService::mapearFila() —
     * sum(cuotas.monto_total) - sum(cuotas.monto_abonado), nunca negativo).
     * No es "del día": un crédito vencido lo sigue estando aunque no haya
     * movimiento hoy, así que no hay filtro de fecha acá.
     *
     * @return array{total: float, cantidad: int}
     */
    private function totalVencidos(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo): array
    {
        $query = Credito::query()
            ->where('estado', 'vencido')
            ->with('cuotas');

        $query = $this->creditos->visibleQuery($query, $actor);

        if ($agenciaId) {
            $query->where('agencia_id', $agenciaId);
        }

        if ($tipo) {
            $query->where('tipo_credito', $tipo);
        }

        if ($asesorId) {
            $query->whereHas('cliente', fn (Builder $q) => $q->where('asesor_id', $asesorId));
        }

        $creditos = $query->get();

        $total = $creditos->sum(fn (Credito $credito): float => max(
            (float) $credito->cuotas->sum('monto_total') - (float) $credito->cuotas->sum('monto_abonado'),
            0.0,
        ));

        return ['total' => round($total, 2), 'cantidad' => $creditos->count()];
    }

    /**
     * Mismo alcance que VentaController::index(): todo para sistemas y
     * administrador_general, su agencia para administrador_agencia y solo lo
     * suyo para el asesor.
     *
     * @param  Builder<Venta>  $query
     */
    private function acotarVentas(Builder $query, User $actor): void
    {
        if (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        } elseif ($actor->hasRole('asesor')) {
            $query->where('vendido_por', $actor->id);
        }
    }

    /**
     * Una sola consulta con SUM y COUNT en vez de traer los registros.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array{0: float, 1: int}
     */
    private function sumar(Builder $query, string $columna): array
    {
        $fila = $query
            ->selectRaw("COALESCE(SUM({$columna}), 0) AS total, COUNT(*) AS cantidad")
            ->first();

        return [(float) $fila->total, (int) $fila->cantidad];
    }
}
