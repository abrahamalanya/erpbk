<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Planilla\Models\Planilla;
use App\Modules\Planilla\Models\PlanillaTrabajador;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reporte de planilla con el formato del R01 del PDT Planilla Electrónica
 * (PLAME) de SUNAT — "Trabajadores - Datos de Ingresos, Tributos y Aportes":
 * una fila por trabajador con su documento (código SUNAT), apellidos,
 * nombres, situación, ingresos devengados y pagados, descuentos, tributos del
 * trabajador, neto a pagar y tributos del empleador.
 *
 * Los importes salen de PlanillaTrabajador, que ya los tiene persistidos y
 * congelados al confirmar la planilla: el reporte no recalcula nada, así que
 * sigue igual aunque después se editen las tasas configuradas.
 */
final class ReportePlanillaService
{
    /**
     * Código SUNAT del tipo de documento (tabla 3 del PLAME).
     *
     * @var array<string, string>
     */
    private const CODIGOS_DOCUMENTO = ['dni' => '01', 'ce' => '04', 'ruc' => '06', 'pasaporte' => '07'];

    /**
     * Etiqueta PLAME de la situación del trabajador.
     *
     * @var array<string, string>
     */
    private const SITUACIONES = ['activo' => 'ACTIVO O SUBSIDIADO', 'baja' => 'BAJA'];

    /**
     * Planillas visibles para el actor — mismo alcance que PlanillaPolicy:
     * administrador general y secretaria ven su empresa, administrador de
     * agencia solo la suya.
     *
     * @return Collection<int, Planilla>
     */
    public function planillas(User $actor, ?string $periodo = null): Collection
    {
        return Planilla::query()
            ->when($periodo, fn (Builder $q) => $q->where('periodo', $periodo))
            ->with(['agencia', 'cerradaPor'])
            ->when($actor->hasRole('administrador_agencia'), fn (Builder $q) => $q->where('agencia_id', $actor->agencia_id))
            ->when($actor->hasAnyRole(['administrador_general', 'secretaria']), fn (Builder $q) => $q->where('empresa_id', $actor->empresa_id))
            ->latest('periodo')
            ->latest('id')
            ->get();
    }

    /**
     * El detalle de una planilla con la cabecera del R01 (RUC, empleador,
     * periodo MM/AAAA) y una fila por trabajador.
     *
     * @return array<string, mixed>
     */
    public function detalle(Planilla $planilla): array
    {
        $trabajadores = $planilla->trabajadores()
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->get()
            ->map(fn (PlanillaTrabajador $t): array => self::fila($t))
            ->all();

        $empresa = $planilla->empresa;

        return [
            'planilla_id' => $planilla->id,
            'periodo' => $planilla->periodo,
            'periodo_plame' => substr($planilla->periodo, 5, 2).'/'.substr($planilla->periodo, 0, 4),
            'ruc' => $empresa?->ruc,
            'empleador' => $empresa ? ($empresa->razon_social ?: $empresa->nombre) : null,
            'fecha_pago' => $planilla->fecha_pago->toDateString(),
            'agencia' => $planilla->agencia?->nombre,
            'estado' => $planilla->estado,
            'cerrada_at' => $planilla->cerrada_at?->toISOString(),
            'trabajadores' => $trabajadores,
            'totales' => [
                'ingresos_devengado' => $planilla->total_ingresos,
                'ingresos_pagado' => $planilla->total_ingresos,
                'descuentos' => $planilla->total_descuentos,
                'tributos_trabajador' => $planilla->total_tributos_trabajador,
                'neto' => $planilla->total_neto,
                'aportes_empleador' => $planilla->total_aportes_empleador,
            ],
        ];
    }

    /**
     * Una fila del R01. Los ingresos devengados y pagados son el mismo monto:
     * la planilla registra lo que se paga en el periodo, no sueldos
     * pendientes. Los descuentos son los aplicados en el mes (adelantos,
     * préstamos, faltas).
     *
     * @return array<string, string>
     */
    private static function fila(PlanillaTrabajador $t): array
    {
        return [
            'user_id' => (string) $t->user_id,
            'tipo_documento' => self::CODIGOS_DOCUMENTO[$t->tipo_documento] ?? $t->tipo_documento,
            'numero_documento' => $t->numero_documento,
            'apellidos' => mb_strtoupper($t->apellidos),
            'nombres' => mb_strtoupper($t->nombres),
            'situacion' => self::SITUACIONES[$t->situacion] ?? mb_strtoupper($t->situacion),
            'ingresos_devengado' => $t->ingresos,
            'ingresos_pagado' => $t->ingresos,
            'descuentos' => $t->descuentos_pagado,
            'tributos_trabajador' => $t->tributos_trabajador,
            'neto' => $t->neto,
            'aportes_empleador' => $t->aportes_empleador,
        ];
    }
}
