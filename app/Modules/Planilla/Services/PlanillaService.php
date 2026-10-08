<?php

namespace App\Modules\Planilla\Services;

use App\Modules\Planilla\Models\ConfiguracionPlanilla;
use App\Modules\Planilla\Models\Planilla;
use App\Modules\Planilla\Models\PlanillaDescuento;
use App\Modules\Planilla\Models\PlanillaDescuentoAplicacion;
use App\Modules\Planilla\Models\PlanillaTrabajador;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registra la planilla de un periodo: cabecera, líneas por trabajador, cálculo
 * de aportes y descuentos, y el cierre que congela los totales.
 *
 * Cada línea se calcula sobre la base imponible del trabajador (el sueldo sin
 * bonificaciones para los aportes del trabajador; el sueldo completo para los
 * del empleador). El neto a pagar sale de la fórmula:
 *
 *   neto = ingresos − descuentos_pagado − tributos_trabajador
 *
 * que es exactamente la que el reporte imprime como "Neto a pagar".
 *
 * Los descuentos (adelantos, préstamos, faltas) NO se consumen al armar el
 * borrador: se calcula cuánto se descontaría este mes y ese importe queda
 * persistido en la línea. Recién al cerrar la planilla se descuentan de verdad
 * del `saldo` de cada descuento. Así un borrador que se edita tres veces no
 * consume el adelanto tres veces.
 */
final class PlanillaService
{
    public function __construct(private readonly ConfiguracionPlanillaService $configuracionService) {}

    /**
     * Crea la cabecera de la planilla del periodo, o devuelve la existente si
     * sigue en borrador (idempotente: el frontend puede reintentar sin
     * duplicar). Una cabecera cerrada se devuelve tal cual —la lectura no
     * muta nada— y solo falla quien intente modificarla después.
     *
     * @param  array{periodo: string, fecha_pago: string}  $datos
     */
    public function crear(User $actor, int $empresaId, ?int $agenciaId, array $datos): Planilla
    {
        return DB::transaction(function () use ($actor, $empresaId, $agenciaId, $datos): Planilla {
            $existente = Planilla::query()
                ->where('empresa_id', $empresaId)
                ->where('periodo', $datos['periodo'])
                ->when($agenciaId, fn ($q) => $q->where('agencia_id', $agenciaId), fn ($q) => $q->whereNull('agencia_id'))
                ->first();

            if ($existente) {
                return $existente;
            }

            return Planilla::query()->create([
                'empresa_id' => $empresaId,
                'agencia_id' => $agenciaId,
                'periodo' => $datos['periodo'],
                'fecha_pago' => $datos['fecha_pago'],
                'estado' => 'borrador',
                'registrado_por' => $actor->id,
            ]);
        });
    }

    /**
     * Agrega (o reemplaza) las líneas de los trabajadores indicados, calcula
     * sus aportes con las tasas vigentes y recalcula los totales de la cabecera.
     * Solo admite planillas en borrador.
     *
     * `afp` es el monto del aporte a la AFP declarado a mano en la línea (solo
     * si `es_afp` es true); lo demás sale de las tasas configuradas.
     *
     * @param  list<array{user_id: int, ingresos: string, bonificaciones?: string|null, situacion?: string|null, es_afp?: bool|null, afp?: string|null, descuentos_devengado?: string|null}>  $lineas
     */
    public function registrarLineas(Planilla $planilla, array $lineas): Planilla
    {
        $this->assertBorrador($planilla);

        return DB::transaction(function () use ($planilla, $lineas): Planilla {
            $configuracion = $this->configuracionService->resolverPara($planilla->empresa_id, $planilla->agencia_id);

            foreach ($lineas as $linea) {
                $this->registrarLinea($planilla, $configuracion, $linea);
            }

            return $this->recalcularTotales($planilla->refresh());
        });
    }

    /**
     * Una línea: snapshot de identidad del usuario, aportes sobre la base
     * imponible, descuentos que tocaría pagar este mes y neto.
     *
     * @param  array{user_id: int, ingresos: string, bonificaciones?: string|null, situacion?: string|null, es_afp?: bool|null, afp?: string|null, descuentos_devengado?: string|null}  $linea
     */
    private function registrarLinea(Planilla $planilla, ConfiguracionPlanilla $configuracion, array $linea): void
    {
        $user = User::query()->findOrFail($linea['user_id']);

        $bonificaciones = bcadd((string) ($linea['bonificaciones'] ?? '0'), '0', 2);
        $ingresos = bcadd((string) $linea['ingresos'], $bonificaciones, 2);

        // Los aportes del trabajador van sobre el sueldo sin bonificaciones;
        // los del empleador, sobre el sueldo completo.
        $tributos = $this->configuracionService->tributosTrabajador(
            $configuracion,
            bcsub($ingresos, $bonificaciones, 2),
            (bool) ($linea['es_afp'] ?? false),
            // La AFP se declara a mano por línea: el monto viene del request, no
            // de una tasa global (ver ConfiguracionPlanillaService).
            (string) ($linea['afp'] ?? '0'),
        );
        $aportes = $this->configuracionService->aportesEmpleador($configuracion, $ingresos);

        $descuentosPagados = $this->descuentoDelMes($planilla, $user);

        $trabajador = PlanillaTrabajador::query()->updateOrCreate(
            ['planilla_id' => $planilla->id, 'user_id' => $user->id],
            [
                'empresa_id' => $planilla->empresa_id,
                'agencia_id' => $planilla->agencia_id ?? $user->agencia_id,
                'tipo_documento' => 'dni',
                'numero_documento' => (string) $user->dni,
                'apellidos' => $user->apellido,
                'nombres' => $user->nombre,
                'situacion' => $linea['situacion'] ?? 'activo',
                'ingresos' => $ingresos,
                'descuentos_devengado' => bcadd((string) ($linea['descuentos_devengado'] ?? '0'), '0', 2),
                'descuentos_pagado' => $descuentosPagados,
                ...$tributos,
                ...$aportes,
                'neto' => '0.00',
            ],
        );

        // neto se resuelve con la fórmula del modelo para que el preview del
        // borrador y la línea cerrada no puedan divergir.
        $trabajador->update(['neto' => $trabajador->netoCalculado()]);
    }

    /**
     * Cuánto se le descontaría a este trabajador este mes: suma lo que queda de
     * cada descuento pendiente, topado por su cuota mensual (sin cuota = de una
     * vez, el saldo entero). Solo mira los saldos; el descuento real ocurre en
     * cerrar().
     */
    private function descuentoDelMes(Planilla $planilla, User $user): string
    {
        return PlanillaDescuento::query()
            ->where('empresa_id', $planilla->empresa_id)
            ->where('user_id', $user->id)
            ->where('estado', 'pendiente')
            ->where('saldo', '>', 0)
            ->orderBy('fecha_registro')
            ->get()
            ->reduce(
                // min(cuota, saldo) — sin cuota, el saldo entero de una vez.
                fn (string $total, PlanillaDescuento $descuento): string => bcadd($total, $descuento->cuotaDelMes(), 2),
                '0.00',
            );
    }

    /**
     * Recalcula los totales de la cabecera sumando sus líneas. Con bcmath (no
     * Collection::sum) para no perder centavos en un estado de resultados.
     */
    public function recalcularTotales(Planilla $planilla): Planilla
    {
        $lineas = $planilla->trabajadores()->get();

        $planilla->update([
            'total_ingresos' => $this->sumar($lineas, 'ingresos'),
            'total_descuentos' => $this->sumar($lineas, 'descuentos_pagado'),
            'total_tributos_trabajador' => $this->sumar($lineas, 'tributos_trabajador'),
            'total_neto' => $this->sumar($lineas, 'neto'),
            'total_aportes_empleador' => $this->sumar($lineas, 'aportes_empleador'),
        ]);

        return $planilla->refresh();
    }

    /**
     * Reabre una planilla confirmada para poder editarla y volver a cerrarla.
     *
     * Invierte los descuentos que el cierre consumió (devolviéndolos a su
     * `saldo`, con el rastro de planilla_descuento_aplicaciones) y vuelve el
     * estado a borrador. Es lo que hace falta cuando un sueldo se digitó mal y
     * hay que corregirlo: sin esta reversión, reabrir y volver a cerrar
     * descontaría los adelantos dos veces.
     */
    public function reabrir(Planilla $planilla): Planilla
    {
        if ($planilla->esBorrador()) {
            throw new DomainException('La planilla ya está en borrador.');
        }

        return DB::transaction(function () use ($planilla): Planilla {
            foreach ($planilla->trabajadores()->get() as $linea) {
                $this->devolverDescuentos($linea);
            }

            $planilla->update([
                'estado' => 'borrador',
                'cerrada_por' => null,
                'cerrada_at' => null,
            ]);

            return $planilla->refresh();
        });
    }

    /**
     * Cierra la planilla: recalcula y congela los totales, y recién ahí consume
     * de verdad los descuentos de cada línea contra el `saldo` de los
     * descuentos pendientes.
     */
    public function cerrar(User $actor, Planilla $planilla): Planilla
    {
        $this->assertBorrador($planilla);

        return DB::transaction(function () use ($actor, $planilla): Planilla {
            $this->recalcularTotales($planilla->fresh());

            foreach ($planilla->trabajadores()->with('trabajador')->get() as $linea) {
                $this->consumirDescuentos($planilla, $linea);
            }

            $planilla->update([
                'estado' => 'confirmada',
                'cerrada_por' => $actor->id,
                'cerrada_at' => now(),
            ]);

            return $planilla->refresh();
        });
    }

    /**
     * Descuenta el importe ya persistido en la línea (`descuentos_pagado`) de
     * los descuentos pendientes del trabajador, oldest first, y deja registro
     * de cada descuento tocado en planilla_descuento_aplicaciones — el rastro
     * que usa devolverDescuentos() para revertir exactamente lo mismo si la
     * planilla se reabre.
     */
    private function consumirDescuentos(Planilla $planilla, PlanillaTrabajador $linea): void
    {
        $porDescontar = $linea->descuentos_pagado;

        if (bccomp($porDescontar, '0', 2) <= 0) {
            return;
        }

        $pendientes = PlanillaDescuento::query()
            ->where('empresa_id', $planilla->empresa_id)
            ->where('user_id', $linea->user_id)
            ->where('estado', 'pendiente')
            ->where('saldo', '>', 0)
            ->orderBy('fecha_registro')
            ->orderBy('id')
            ->get();

        foreach ($pendientes as $descuento) {
            if (bccomp($porDescontar, '0', 2) <= 0) {
                break;
            }

            $aplicado = bccomp($porDescontar, $descuento->saldo, 2) >= 0 ? $descuento->saldo : $porDescontar;
            $saldoNuevo = bcsub($descuento->saldo, $aplicado, 2);

            $descuento->update([
                'pagado' => bcadd($descuento->pagado, $aplicado, 2),
                'saldo' => $saldoNuevo,
                'estado' => bccomp($saldoNuevo, '0', 2) <= 0 ? 'saldado' : 'pendiente',
            ]);

            PlanillaDescuentoAplicacion::query()->updateOrCreate(
                [
                    'planilla_trabajador_id' => $linea->id,
                    'planilla_descuento_id' => $descuento->id,
                ],
                [
                    'planilla_id' => $planilla->id,
                    'monto' => $aplicado,
                ],
            );

            $porDescontar = bcsub($porDescontar, $aplicado, 2);
        }
    }

    /**
     * Reversión exacta de consumirDescuentos(): por cada descuento que esta
     * planilla tocó, devuelve el monto registrado a su `saldo`, lo saca de
     * `pagado` y vuelve a 'pendiente'. Se guía por el rastro de aplicaciones,
     * no por el orden de los descuentos, para que devolver un adelanto que ya
     * estaba saldado lo devuelva a ESE descuento y no a otro.
     */
    private function devolverDescuentos(PlanillaTrabajador $linea): void
    {
        $aplicaciones = PlanillaDescuentoAplicacion::query()
            ->where('planilla_trabajador_id', $linea->id)
            ->with('descuento')
            ->get();

        foreach ($aplicaciones as $aplicacion) {
            $descuento = $aplicacion->descuento;

            if (! $descuento) {
                continue;
            }

            $descuento->update([
                'saldo' => bcadd($descuento->saldo, $aplicacion->monto, 2),
                'pagado' => bcsub($descuento->pagado, $aplicacion->monto, 2),
                'estado' => 'pendiente',
            ]);
        }

        PlanillaDescuentoAplicacion::query()
            ->where('planilla_trabajador_id', $linea->id)
            ->delete();
    }

    /**
     * @param  Collection<int, PlanillaTrabajador>  $lineas
     */
    private function sumar(Collection $lineas, string $columna): string
    {
        return $lineas->reduce(
            fn (string $acumulado, PlanillaTrabajador $linea): string => bcadd($acumulado, $linea->{$columna}, 2),
            '0.00',
        );
    }

    private function assertBorrador(Planilla $planilla): void
    {
        if (! $planilla->esBorrador()) {
            throw new DomainException('Solo una planilla en borrador admite cambios.');
        }
    }
}
