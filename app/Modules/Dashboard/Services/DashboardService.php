<?php

namespace App\Modules\Dashboard\Services;

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Services\CajaBovedaHierarchyService;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Ruta\Services\RutaCobranzaService;
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
 * "Ingresos" son los ingresos de caja (tipo=ingreso) — depósitos/aportes que
 * no vienen de cobrarle a un cliente, deliberadamente distintos de
 * "cobranza" por la misma razón que "egresos" lo es de "desembolso".
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
        private readonly RutaCobranzaService $rutaCobranza,
    ) {}

    /**
     * Resumen del período [$desde, $hasta] para el actor, acotado por los
     * filtros opcionales. `vencidos` queda fuera del rango a propósito: es la
     * cartera vencida visible AHORA, no un flujo del período (ver
     * totalVencidos()).
     *
     * @return array<string, mixed>
     */
    public function resumenDelDia(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipoCredito, string $desde, string $hasta): array
    {
        $cobranza = $this->totalCobranzas($actor, $agenciaId, $asesorId, $tipoCredito, $desde, $hasta);
        $desembolso = $this->totalDesembolsos($actor, $agenciaId, $asesorId, $tipoCredito, $desde, $hasta);

        return [
            // Alias de compatibilidad con clientes (app móvil) que todavía no
            // conocen desde/hasta: con el rango por defecto (hoy = hoy) vale
            // exactamente lo mismo que antes de que este filtro existiera.
            'fecha' => $hasta,
            'desde' => $desde,
            'hasta' => $hasta,
            'cobranza' => $cobranza,
            'desembolso' => $desembolso,
            // Lo que quedó en caja por la operación del período. Puede ser
            // negativo: es la diferencia, no un saldo de cuentas.
            'neto' => round($cobranza['total'] - $desembolso['total'], 2),
            'egresos' => $this->totalEgresos($actor, $agenciaId, $asesorId, $desde, $hasta),
            // Ingresos de caja manuales (tipo=ingreso) — distinto de `cobranza`
            // (cobros sobre créditos + pagos de venta): un ingreso manual es un
            // depósito/aporte que no viene de cobrarle a un cliente. Para la
            // tarjeta "Ingreso" del panel y el módulo Ingresos.
            'ingresos' => $this->totalIngresos($actor, $agenciaId, $asesorId, $desde, $hasta),
            'vencidos' => $this->totalVencidos($actor, $agenciaId, $asesorId, $tipoCredito),
        ];
    }

    /**
     * Cobranza vs. desembolso del período [$desde, $hasta], agrupados para
     * compararlos entre agencias, asesores o tipos de crédito a la vez — a
     * diferencia de resumenDelDia(), que da un solo total para el ámbito ya
     * filtrado. Mismos filtros y misma jerarquía de visibilidad que el resumen.
     *
     * @return array<string, mixed>
     */
    public function graficoCobranzaDesembolso(
        User $actor,
        ?int $agenciaId,
        ?int $asesorId,
        ?string $tipoCredito,
        string $agruparPor,
        string $desde,
        string $hasta,
    ): array {
        $cobranza = $this->registrosCobranza($actor, $agenciaId, $asesorId, $tipoCredito, $desde, $hasta);
        $desembolso = $this->registrosDesembolso($actor, $agenciaId, $asesorId, $tipoCredito, $desde, $hasta);

        $filas = match ($agruparPor) {
            self::AGRUPACION_ASESOR => $this->agruparPorAsesor($cobranza, $desembolso),
            self::AGRUPACION_TIPO_CREDITO => $this->agruparPorTipo($cobranza, $desembolso),
            self::AGRUPACION_AGENCIA_TIPO_CREDITO => $this->agruparPorAgenciaYTipo($cobranza, $desembolso),
            default => $this->agruparPorAgencia($cobranza, $desembolso),
        };

        return [
            // Alias de compatibilidad (ver resumenDelDia()).
            'fecha' => $hasta,
            'desde' => $desde,
            'hasta' => $hasta,
            'agrupado_por' => $agruparPor,
            'filas' => $filas,
        ];
    }

    /**
     * Igual que totalCobranzas() pero devuelve las filas con sus columnas de
     * agrupación (agencia, tipo, asesor) en vez de un total ya sumado — es la
     * materia prima de graficoCobranzaDesembolso(). Mismo scoping, mismos
     * filtros, mismo rango [$desde, $hasta].
     *
     * @return Collection<int, array{agencia_id: ?int, agencia_nombre: string, asesor_id: ?int, asesor_nombre: string, tipo: string, monto: float}>
     */
    private function registrosCobranza(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo, string $desde, string $hasta): Collection
    {
        $filas = collect();

        if ($tipo !== self::TIPO_VENTAS) {
            $cobros = Cobro::query()
                ->where('estado', 'registrado')
                ->whereDate('created_at', '>=', $desde)
                ->whereDate('created_at', '<=', $hasta)
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
                ->whereDate('created_at', '>=', $desde)
                ->whereDate('created_at', '<=', $hasta)
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
     * Igual que totalDesembolsos() pero con las columnas de agrupación, y
     * sobre un RANGO de fechas en vez de un solo día — graficoCobranzaDesembolso()
     * llama con $desde = $hasta = hoy; produccionPorAsesor() con un período
     * elegido. Las ventas de tienda no se desembolsan (ver
     * totalDesembolsos()), así que acá tampoco aportan filas.
     *
     * @return Collection<int, array{agencia_id: ?int, agencia_nombre: string, asesor_id: ?int, asesor_nombre: string, tipo: string, monto: float}>
     */
    private function registrosDesembolso(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo, string $desde, string $hasta): Collection
    {
        if ($tipo === self::TIPO_VENTAS) {
            return collect();
        }

        $query = CajaMovimiento::query()
            ->desembolsos()
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta)
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
     * Créditos generados por asesor y tipo, en un período — "generado"
     * significa efectivamente DESEMBOLSADO (reutiliza registrosDesembolso(),
     * la misma fuente que ya define "Desembolso" en
     * graficoCobranzaDesembolso()), no solicitado ni aprobado: una solicitud
     * que nunca llegó a desembolsarse no aporta aquí. La atribución al
     * asesor es quien PROCESÓ el desembolso (CajaMovimiento.registrado_por,
     * igual que agruparPorAsesor() de ese mismo gráfico) y no
     * credito.registrado_por (quién originó la solicitud) — en el flujo
     * normal son la misma persona (desembolsar exige que el actor tenga su
     * propia caja con saldo), pero podrían diferir en una adenda o un
     * refinanciamiento; se mantiene esta atribución para no tener dos
     * definiciones de "asesor del desembolso" en el mismo dashboard.
     *
     * Igual que agruparPorAsesor()/agruparPorAgenciaYTipo(): los 4 tipos
     * siempre se devuelven dentro de cada asesor (relleno con cero), pero un
     * asesor sin producción en el período no aparece. Ordenado por total
     * generado descendente.
     *
     * @return array<string, mixed>
     */
    public function produccionPorAsesor(
        User $actor,
        string $desde,
        string $hasta,
        ?int $agenciaId,
        ?int $asesorId,
        ?string $tipoCredito,
    ): array {
        $registros = $this->registrosDesembolso($actor, $agenciaId, $asesorId, $tipoCredito, $desde, $hasta);

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'filas' => $this->agruparPorAsesorYTipo($registros),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $registros
     * @return list<array{asesor_id: int, asesor_nombre: string, total: float, cantidad: int, tipos: list<array<string, mixed>>}>
     */
    private function agruparPorAsesorYTipo(Collection $registros): array
    {
        $claves = $registros->pluck('asesor_id')->filter()->unique()->values();
        $porAsesor = $registros->groupBy('asesor_id');

        return $claves
            ->map(function (int $asesorId) use ($porAsesor): array {
                $grupo = $porAsesor->get($asesorId, collect());

                return [
                    'asesor_id' => $asesorId,
                    'asesor_nombre' => $grupo->first()['asesor_nombre'],
                    'total' => round((float) $grupo->sum('monto'), 2),
                    'cantidad' => $grupo->count(),
                    'tipos' => $this->rellenarPorTipo($grupo),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Los 4 TIPOS_CREDITO siempre presentes (relleno con cero) — mismo
     * criterio que agruparPorTipo(), pero con una sola magnitud (monto) en
     * vez del par cobranza/desembolso: produccionPorAsesor() no tiene nada
     * que comparar contra el monto generado.
     *
     * @param  Collection<int, array<string, mixed>>  $registros
     * @return list<array{tipo_credito: string, monto: float, cantidad: int}>
     */
    private function rellenarPorTipo(Collection $registros): array
    {
        $porTipo = $registros->groupBy('tipo');

        return collect(self::TIPOS_CREDITO)
            ->map(function (string $tipo) use ($porTipo): array {
                $grupo = $porTipo->get($tipo, collect());

                return [
                    'tipo_credito' => $tipo,
                    'monto' => round((float) $grupo->sum('monto'), 2),
                    'cantidad' => $grupo->count(),
                ];
            })
            ->all();
    }

    /**
     * @return array{total: float, cantidad: int}
     */
    private function totalCobranzas(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo, string $desde, string $hasta): array
    {
        $total = 0.0;
        $cantidad = 0;

        // Créditos: skipped cuando el filtro es ventas (no son créditos).
        if ($tipo !== self::TIPO_VENTAS) {
            $cobros = Cobro::query()
                ->where('estado', 'registrado')
                ->whereDate('created_at', '>=', $desde)
                ->whereDate('created_at', '<=', $hasta)
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
                ->whereDate('created_at', '>=', $desde)
                ->whereDate('created_at', '<=', $hasta)
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
    private function totalDesembolsos(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo, string $desde, string $hasta): array
    {
        // Las ventas de tienda no se financian con desembolso de crédito, así
        // que ese filtro no aplica acá: el total queda en cero.
        if ($tipo === self::TIPO_VENTAS) {
            return ['total' => 0.0, 'cantidad' => 0];
        }

        $query = CajaMovimiento::query()
            ->desembolsos()
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta)
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
     * Egresos manuales de caja del período (gastos generales) — deliberadamente
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
    private function totalEgresos(User $actor, ?int $agenciaId, ?int $asesorId, string $desde, string $hasta): array
    {
        if (! app(ModuloService::class)->perfilPermite($actor, 'movimientos', 'mi_caja', 'cajas')) {
            return ['total' => 0.0, 'cantidad' => 0];
        }

        $query = CajaMovimiento::query()
            ->egresosManuales()
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta)
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
     * Ingresos manuales de caja del período (tipo=ingreso) — depósitos/aportes
     * que no vienen de cobrarle a un cliente, a diferencia de `cobranza`
     * (cobros sobre créditos + pagos de venta). Mismo criterio de visibilidad
     * de caja que totalEgresos(); `tipo_credito` tampoco aplica aquí por la
     * misma razón.
     *
     * @return array{total: float, cantidad: int}
     */
    private function totalIngresos(User $actor, ?int $agenciaId, ?int $asesorId, string $desde, string $hasta): array
    {
        if (! app(ModuloService::class)->perfilPermite($actor, 'movimientos', 'mi_caja', 'cajas')) {
            return ['total' => 0.0, 'cantidad' => 0];
        }

        $query = CajaMovimiento::query()
            ->where('tipo', 'ingreso')
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta)
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
        $creditos = $this->creditosDeCartera(['vencido'], $actor, $agenciaId, $asesorId, $tipo);

        $total = $creditos->sum(fn (Credito $credito): float => $this->saldoPendienteDe($credito));

        return ['total' => round($total, 2), 'cantidad' => $creditos->count()];
    }

    /**
     * Composición de la cartera activa (créditos `activo` + `vencido`, es
     * decir toda la cartera viva, no solo la mora que ya cubre "Vencidos")
     * por tipo de crédito — para ver concentración de riesgo por línea de
     * negocio. Las ventas de tienda no entran: no son un crédito con cuotas,
     * así que no tienen saldo pendiente de esta forma. Los 4 tipos siempre
     * se devuelven (rellenados con cero si no hay cartera de ese tipo), igual
     * que el resto de desgloses por tipo de este servicio.
     *
     * @return array<string, mixed>
     */
    public function carteraActivaPorTipo(User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipoCredito): array
    {
        $creditos = $this->creditosDeCartera(['activo', 'vencido'], $actor, $agenciaId, $asesorId, $tipoCredito);

        $porTipo = $creditos->groupBy('tipo_credito');

        $filas = collect(self::TIPOS_CREDITO)
            ->map(function (string $tipo) use ($porTipo): array {
                $grupo = $porTipo->get($tipo, collect());

                return [
                    'tipo_credito' => $tipo,
                    'saldo' => round((float) $grupo->sum(fn (Credito $c): float => $this->saldoPendienteDe($c)), 2),
                    'cantidad' => $grupo->count(),
                ];
            })
            ->all();

        return ['filas' => $filas];
    }

    /**
     * Créditos de cartera (viva) visibles para el actor, con sus cuotas
     * cargadas — query base compartida por totalVencidos() y
     * carteraActivaPorTipo(). El filtro de asesor mira cliente.asesor_id (a
     * quién pertenece el cliente), no credito.registrado_por (quién registró
     * la operación): es cartera, no cobranza/desembolso.
     *
     * @param  list<string>  $estados
     * @return Collection<int, Credito>
     */
    private function creditosDeCartera(array $estados, User $actor, ?int $agenciaId, ?int $asesorId, ?string $tipo): Collection
    {
        $query = Credito::query()
            ->whereIn('estado', $estados)
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

        return $query->get();
    }

    /**
     * Saldo pendiente de un crédito: lo que falta de sus cuotas programadas,
     * nunca negativo (un crédito sobrepagado no resta cartera).
     */
    private function saldoPendienteDe(Credito $credito): float
    {
        return max(
            (float) $credito->cuotas->sum('monto_total') - (float) $credito->cuotas->sum('monto_abonado'),
            0.0,
        );
    }

    /**
     * Avance de cobranza de HOY por asesor: cuánto debía cobrar (todas las
     * cuotas pendientes con vencimiento hasta hoy, no solo las que vencen
     * exactamente hoy — mismo criterio de "pendiente" que usa
     * RutaCobranzaService::clientesEnMoraDe() para armar la ruta: cuotas sin
     * pagada_at con fecha_vencimiento <= hoy), cuánto ha cobrado y cuánto le
     * falta. Pensado para identificar rápido a quién le falta cobrar,
     * incluyendo lo atrasado de días previos — no solo el cobro del día.
     *
     * "Cobrado" lee cuotas.monto_abonado (mismo campo que ya usan
     * totalVencidos()/carteraActivaPorTipo() para "lo pagado"), no una suma
     * de Cobro.created_at = hoy: atribuir cada Cobro a la cuota exacta que
     * paga requeriría la relación CobroCuotaAbono, que no siempre está
     * poblada (p. ej. pago de una sola cuota), y duplicaría esa lógica. Esto
     * también maneja bien el pago adelantado: una cuota que hoy recién vence
     * pero ya se abonó antes sale como cobrada, tal como pide el negocio.
     *
     * El dueño de la obligación es cliente.asesor_id (igual que
     * RutaCobranzaService), no credito.registrado_por — por eso el alcance
     * acá NO es CreditoHierarchyService::visibleQuery sino la lista de
     * asesores que el actor puede ver
     * (RutaCobranzaService::asesoresVisibles()), igual que la propia Ruta.
     * Esa lista queda vacía a propósito para un actor con rol asesor (está
     * pensada para el selector de OTRA pantalla, donde un asesor ve
     * directamente su propia ruta sin selector) — acá sí necesita verse a sí
     * mismo, así que ese caso se resuelve aparte.
     *
     * A diferencia de los demás desgloses por asesor de este servicio (que
     * no rellenan con cero a quien no tuvo movimiento), este SÍ lista a
     * todos los asesores visibles, incluidos los que no tienen nada
     * programado hoy: es un roster de cumplimiento, no una categoría
     * dinámica.
     *
     * @return array<string, mixed>
     */
    public function avanceCobranzaHoy(User $actor, ?int $agenciaId, ?int $asesorId): array
    {
        $hoy = now()->toDateString();

        $asesores = ($actor->hasRole('asesor')
            ? $actor->newCollection([$actor])
            : $this->rutaCobranza->asesoresVisibles($actor))
            ->load('agencia:id,nombre');

        if ($asesorId) {
            $asesores = $asesores->where('id', $asesorId)->values();
        }

        if ($agenciaId) {
            $asesores = $asesores->where('agencia_id', $agenciaId)->values();
        }

        $asesorIds = $asesores->pluck('id');

        $cuotas = CuotaCredito::query()
            ->pendientes()
            ->whereDate('fecha_vencimiento', '<=', $hoy)
            ->whereHas('credito', function (Builder $q) use ($asesorIds, $agenciaId): void {
                $q->whereIn('estado', ['activo', 'vencido'])
                    ->whereHas('cliente', fn (Builder $c) => $c->whereIn('asesor_id', $asesorIds));

                if ($agenciaId) {
                    $q->where('agencia_id', $agenciaId);
                }
            })
            ->with('credito.cliente:id,asesor_id')
            ->get();

        $porAsesor = $cuotas->groupBy(fn (CuotaCredito $c): ?int => $c->credito->cliente->asesor_id);

        $filas = $asesores
            ->map(function (User $asesor) use ($porAsesor): array {
                $cuotasDelAsesor = $porAsesor->get($asesor->id, collect());

                $programado = round((float) $cuotasDelAsesor->sum('monto_total'), 2);
                $cobrado = round((float) $cuotasDelAsesor->sum(
                    fn (CuotaCredito $c): float => min((float) $c->monto_abonado, (float) $c->monto_total)
                ), 2);
                $pendiente = round(max($programado - $cobrado, 0.0), 2);

                return [
                    'asesor_id' => $asesor->id,
                    'asesor_nombre' => trim("{$asesor->nombre} {$asesor->apellido}"),
                    'agencia_id' => $asesor->agencia_id,
                    'agencia_nombre' => $asesor->agencia?->nombre,
                    'programado' => $programado,
                    'cobrado' => $cobrado,
                    'pendiente' => $pendiente,
                    'porcentaje' => $programado > 0 ? round(min($cobrado / $programado * 100, 100), 1) : null,
                ];
            })
            // Avance ascendente (quien menos avanzó primero); sin cobranza
            // programada queda al final, no mezclado con el 0% real.
            ->sortBy(fn (array $f): float => $f['porcentaje'] ?? 101)
            ->values()
            ->all();

        return ['fecha' => $hoy, 'filas' => $filas];
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
