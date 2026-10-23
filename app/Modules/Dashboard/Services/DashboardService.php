<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Sistemas\Services\ModuloService;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\PagoVenta;
use App\Modules\Venta\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

    /** Modos de agrupación del gráfico Cobranza vs. Desembolso. */
    public const AGRUPACION_AGENCIA = 'agencia';

    public const AGRUPACION_ASESOR = 'asesor';

    public const AGRUPACION_TIPO_CREDITO = 'tipo_credito';

    public const AGRUPACION_AGENCIA_TIPO_CREDITO = 'agencia_tipo_credito';

    /**
     * @var list<string>
     */
    public const AGRUPACIONES = [
        self::AGRUPACION_AGENCIA,
        self::AGRUPACION_ASESOR,
        self::AGRUPACION_TIPO_CREDITO,
        self::AGRUPACION_AGENCIA_TIPO_CREDITO,
    ];

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
     * Cobranza vs. desembolso del día, agrupados para compararlos entre
     * agencias, asesores o tipos de crédito a la vez — a diferencia de
     * resumenDelDia(), que da un solo total para el ámbito ya filtrado.
     * Mismos filtros y misma jerarquía de visibilidad que el resumen.
     *
     * @return array<string, mixed>
     */
    public function graficoCobranzaDesembolso(
        User $actor,
        ?int $agenciaId,
        ?int $asesorId,
        ?string $tipoCredito,
        string $agruparPor,
    ): array {
        $hoy = now()->toDateString();

        $cobranza = $this->registrosCobranza($actor, $agenciaId, $asesorId, $tipoCredito, $hoy);
        $desembolso = $this->registrosDesembolso($actor, $agenciaId, $asesorId, $tipoCredito, $hoy);

        $filas = match ($agruparPor) {
            self::AGRUPACION_ASESOR => $this->agruparPorAsesor($cobranza, $desembolso),
            self::AGRUPACION_TIPO_CREDITO => $this->agruparPorTipo($cobranza, $desembolso),
            self::AGRUPACION_AGENCIA_TIPO_CREDITO => $this->agruparPorAgenciaYTipo($cobranza, $desembolso),
            default => $this->agruparPorAgencia($cobranza, $desembolso),
        };

        return [
            'fecha' => $hoy,
            'agrupado_por' => $agruparPor,
            'filas' => $filas,
        ];
    }

    /**
     * Igual que totalCobranzas() pero devuelve las filas con sus columnas de
     * agrupación (agencia, tipo, asesor) en vez de un total ya sumado — es la
     * materia prima de graficoCobranzaDesembolso(). Mismo scoping, mismos
     * filtros, mismo "hoy".
     *
     * @return Collection<int, array{agencia_id: ?int, agencia_nombre: string, asesor_id: ?int, asesor_nombre: string, tipo: string, monto: float}>
     */
    private function registrosCobranza(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo, string $hoy): Collection
    {
        $filas = collect();

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
                })
                ->with(['credito.agencia:id,nombre', 'registradoPor:id,nombre,apellido']);

            if ($asesorId) {
                $cobros->where('registrado_por', $asesorId);
            }

            $filas = $filas->merge($cobros->get()->map(fn (Cobro $c): array => $this->fila(
                $c->credito->agencia_id,
                $c->credito->agencia?->nombre ?? '—',
                $c->registrado_por,
                $c->registradoPor,
                $c->credito->tipo_credito,
                (float) $c->monto_pagado,
            )));
        }

        if (($tipo === null || $tipo === self::TIPO_VENTAS) && app(ModuloService::class)->perfilPermite($actor, 'ventas')) {
            $pagos = PagoVenta::query()
                ->whereNull('anulado_at')
                ->whereDate('created_at', $hoy)
                ->whereHas('venta', function (Builder $q) use ($actor, $agenciaId): void {
                    $this->acotarVentas($q, $actor);

                    if ($agenciaId) {
                        $q->where('agencia_id', $agenciaId);
                    }
                })
                ->with(['venta.agencia:id,nombre', 'registradoPor:id,nombre,apellido']);

            if ($asesorId) {
                $pagos->where('registrado_por', $asesorId);
            }

            $filas = $filas->merge($pagos->get()->map(fn (PagoVenta $p): array => $this->fila(
                $p->venta->agencia_id,
                $p->venta->agencia?->nombre ?? '—',
                $p->registrado_por,
                $p->registradoPor,
                self::TIPO_VENTAS,
                (float) $p->monto,
            )));
        }

        return $filas;
    }

    /**
     * Igual que totalDesembolsos() pero con las columnas de agrupación. Las
     * ventas de tienda no se desembolsan (ver totalDesembolsos()), así que
     * acá tampoco aportan filas.
     *
     * @return Collection<int, array{agencia_id: ?int, agencia_nombre: string, asesor_id: ?int, asesor_nombre: string, tipo: string, monto: float}>
     */
    private function registrosDesembolso(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo, string $hoy): Collection
    {
        if ($tipo === self::TIPO_VENTAS) {
            return collect();
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
            })
            ->with(['credito.agencia:id,nombre', 'registradoPor:id,nombre,apellido']);

        if ($asesorId) {
            $query->where('registrado_por', $asesorId);
        }

        return $query->get()->map(fn (CajaMovimiento $m): array => $this->fila(
            $m->credito->agencia_id,
            $m->credito->agencia?->nombre ?? '—',
            $m->registrado_por,
            $m->registradoPor,
            $m->credito->tipo_credito,
            (float) $m->monto,
        ));
    }

    /**
     * @return array{agencia_id: ?int, agencia_nombre: string, asesor_id: ?int, asesor_nombre: string, tipo: string, monto: float}
     */
    private function fila(?int $agenciaId, string $agenciaNombre, ?int $asesorId, ?User $asesor, string $tipo, float $monto): array
    {
        return [
            'agencia_id' => $agenciaId,
            'agencia_nombre' => $agenciaNombre,
            'asesor_id' => $asesorId,
            'asesor_nombre' => $asesor ? trim("{$asesor->nombre} {$asesor->apellido}") : '—',
            'tipo' => $tipo,
            'monto' => $monto,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array{total: float, cantidad: int}
     */
    private function totalDe(Collection $filas): array
    {
        return ['total' => round((float) $filas->sum('monto'), 2), 'cantidad' => $filas->count()];
    }

    /**
     * Por agencia: solo las que tuvieron cobranza o desembolso — a propósito
     * no se rellenan con cero las agencias sin movimiento (mismo criterio que
     * ReporteCobranzaMensualService::totalesPorAsesor()), porque a diferencia
     * de los tipos de crédito (un enum fijo que cualquier rol ya ve en su
     * propio selector), qué agencias existen es información con alcance por
     * rol: listarlas en cero filtraría por inferencia agencias que un
     * administrador_agencia o un asesor no deberían poder ver.
     *
     * @param  Collection<int, array<string, mixed>>  $cobranza
     * @param  Collection<int, array<string, mixed>>  $desembolso
     * @return list<array{agencia_id: int, agencia_nombre: string, cobranza: array{total: float, cantidad: int}, desembolso: array{total: float, cantidad: int}}>
     */
    private function agruparPorAgencia(Collection $cobranza, Collection $desembolso): array
    {
        $claves = $cobranza->pluck('agencia_id')->merge($desembolso->pluck('agencia_id'))
            ->filter()->unique()->values();

        $porAgenciaCobranza = $cobranza->groupBy('agencia_id');
        $porAgenciaDesembolso = $desembolso->groupBy('agencia_id');

        return $claves
            ->map(function (int $agenciaId) use ($porAgenciaCobranza, $porAgenciaDesembolso): array {
                $grupoC = $porAgenciaCobranza->get($agenciaId, collect());
                $grupoD = $porAgenciaDesembolso->get($agenciaId, collect());

                return [
                    'agencia_id' => $agenciaId,
                    'agencia_nombre' => $grupoC->first()['agencia_nombre'] ?? $grupoD->first()['agencia_nombre'] ?? '—',
                    'cobranza' => $this->totalDe($grupoC),
                    'desembolso' => $this->totalDe($grupoD),
                ];
            })
            ->sortBy('agencia_nombre')
            ->values()
            ->all();
    }

    /**
     * Por asesor (quien registró el cobro/desembolso): mismo criterio de "sin
     * relleno de ceros" que agruparPorAgencia(), y por la misma razón.
     *
     * @param  Collection<int, array<string, mixed>>  $cobranza
     * @param  Collection<int, array<string, mixed>>  $desembolso
     * @return list<array{asesor_id: int, asesor_nombre: string, cobranza: array{total: float, cantidad: int}, desembolso: array{total: float, cantidad: int}}>
     */
    private function agruparPorAsesor(Collection $cobranza, Collection $desembolso): array
    {
        $claves = $cobranza->pluck('asesor_id')->merge($desembolso->pluck('asesor_id'))
            ->filter()->unique()->values();

        $porAsesorCobranza = $cobranza->groupBy('asesor_id');
        $porAsesorDesembolso = $desembolso->groupBy('asesor_id');

        return $claves
            ->map(function (int $asesorId) use ($porAsesorCobranza, $porAsesorDesembolso): array {
                $grupoC = $porAsesorCobranza->get($asesorId, collect());
                $grupoD = $porAsesorDesembolso->get($asesorId, collect());

                return [
                    'asesor_id' => $asesorId,
                    'asesor_nombre' => $grupoC->first()['asesor_nombre'] ?? $grupoD->first()['asesor_nombre'] ?? '—',
                    'cobranza' => $this->totalDe($grupoC),
                    'desembolso' => $this->totalDe($grupoD),
                ];
            })
            ->sortBy('asesor_nombre')
            ->values()
            ->all();
    }

    /**
     * Por tipo de crédito: a diferencia de agencia/asesor, acá SÍ se rellenan
     * las TIPOS (incluida 'ventas') con cero cuando no hubo movimiento — es un
     * enum fijo y público (el mismo que ya ofrece el selector "Tipo" a
     * cualquier rol), así que completarlo no filtra nada y permite comparar
     * siempre las mismas categorías.
     *
     * @param  Collection<int, array<string, mixed>>  $cobranza
     * @param  Collection<int, array<string, mixed>>  $desembolso
     * @return list<array{tipo_credito: string, cobranza: array{total: float, cantidad: int}, desembolso: array{total: float, cantidad: int}}>
     */
    private function agruparPorTipo(Collection $cobranza, Collection $desembolso): array
    {
        $porTipoCobranza = $cobranza->groupBy('tipo');
        $porTipoDesembolso = $desembolso->groupBy('tipo');

        return collect(self::TIPOS)
            ->map(fn (string $tipo): array => [
                'tipo_credito' => $tipo,
                'cobranza' => $this->totalDe($porTipoCobranza->get($tipo, collect())),
                'desembolso' => $this->totalDe($porTipoDesembolso->get($tipo, collect())),
            ])
            ->all();
    }

    /**
     * Desglose por agencia y tipo de crédito: agencias sin relleno de cero
     * (mismo criterio que agruparPorAgencia()), con sus TIPOS siempre
     * completos dentro (mismo criterio que agruparPorTipo()). El total de
     * `cobranza`/`desembolso` de cada agencia es la suma de sus `tipos`.
     *
     * @param  Collection<int, array<string, mixed>>  $cobranza
     * @param  Collection<int, array<string, mixed>>  $desembolso
     * @return list<array{agencia_id: int, agencia_nombre: string, cobranza: array{total: float, cantidad: int}, desembolso: array{total: float, cantidad: int}, tipos: list<array<string, mixed>>}>
     */
    private function agruparPorAgenciaYTipo(Collection $cobranza, Collection $desembolso): array
    {
        $claves = $cobranza->pluck('agencia_id')->merge($desembolso->pluck('agencia_id'))
            ->filter()->unique()->values();

        $porAgenciaCobranza = $cobranza->groupBy('agencia_id');
        $porAgenciaDesembolso = $desembolso->groupBy('agencia_id');

        return $claves
            ->map(function (int $agenciaId) use ($porAgenciaCobranza, $porAgenciaDesembolso): array {
                $grupoC = $porAgenciaCobranza->get($agenciaId, collect());
                $grupoD = $porAgenciaDesembolso->get($agenciaId, collect());

                return [
                    'agencia_id' => $agenciaId,
                    'agencia_nombre' => $grupoC->first()['agencia_nombre'] ?? $grupoD->first()['agencia_nombre'] ?? '—',
                    'cobranza' => $this->totalDe($grupoC),
                    'desembolso' => $this->totalDe($grupoD),
                    'tipos' => $this->agruparPorTipo($grupoC, $grupoD),
                ];
            })
            ->sortBy('agencia_nombre')
            ->values()
            ->all();
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
        if (($tipo === null || $tipo === self::TIPO_VENTAS) && app(ModuloService::class)->perfilPermite($actor, 'ventas')) {
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
        if (! app(ModuloService::class)->perfilPermite($actor, 'movimientos', 'mi_caja', 'cajas')) {
            return ['total' => 0.0, 'cantidad' => 0];
        }

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
