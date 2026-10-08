<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Planilla\Models\Planilla;
use App\Modules\Planilla\Models\PlanillaTrabajador;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reporte de planilla: una fila por trabajador del periodo, con los datos
 * declarados (documento, apellidos, nombres, situación) y los importes de
 * ingresos, descuentos, tributos y aportes.
 *
 * Los importes salen de PlanillaTrabajador, que ya los tiene persistidos y
 * congelados al confirmar la planilla: el reporte no recalcula nada, así que
 * sigue igual aunque después se editen las tasas configuradas.
 */
final class ReportePlanillaService
{
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
     * El detalle de una planilla: una fila por trabajador con los datos
     * declarados y todos los importes del reporte.
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

        return [
            'planilla_id' => $planilla->id,
            'periodo' => $planilla->periodo,
            'fecha_pago' => $planilla->fecha_pago->toDateString(),
            'agencia' => $planilla->agencia?->nombre,
            'estado' => $planilla->estado,
            'cerrada_at' => $planilla->cerrada_at?->toISOString(),
            'trabajadores' => $trabajadores,
            'totales' => [
                'ingresos' => $planilla->total_ingresos,
                'descuentos_devengado' => $this->sumar($trabajadores, 'descuentos_devengado'),
                'descuentos_pagado' => $planilla->total_descuentos,
                'tributos_trabajador' => $planilla->total_tributos_trabajador,
                'neto' => $planilla->total_neto,
                'aportes_empleador' => $planilla->total_aportes_empleador,
            ],
        ];
    }

    /**
     * Una fila del reporte, con los nombres de columna de la tabla del
     * enunciado.
     *
     * @return array<string, string>
     */
    private static function fila(PlanillaTrabajador $t): array
    {
        return [
            'user_id' => (string) $t->user_id,
            'tipo_documento' => $t->tipo_documento,
            'numero_documento' => $t->numero_documento,
            'apellidos' => $t->apellidos,
            'nombres' => $t->nombres,
            'situacion' => $t->situacion,
            'ingresos' => $t->ingresos,
            'descuentos_devengado' => $t->descuentos_devengado,
            'descuentos_pagado' => $t->descuentos_pagado,
            'tributos_trabajador' => $t->tributos_trabajador,
            'neto' => $t->neto,
            'aportes_empleador' => $t->aportes_empleador,
        ];
    }

    /**
     * Suma con bcmath sobre las filas ya formateadas (string de 2 decimales).
     *
     * @param  list<array<string, string>>  $filas
     */
    private function sumar(array $filas, string $columna): string
    {
        return array_reduce(
            $filas,
            fn (string $acumulado, array $fila): string => bcadd($acumulado, $fila[$columna], 2),
            '0.00',
        );
    }
}
