<?php

namespace App\Modules\Planilla\Services;

use App\Modules\Planilla\Models\ConfiguracionPlanilla;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tasas de la planilla, todas sobre el sueldo básico (sin bonificaciones):
 *
 * - Trabajador: AFP 11.37% u ONP 13% (se descuenta del sueldo).
 * - Empleador: EsSalud 9% o SIS S/ 15 fijos (costo de la empresa).
 *
 * Qué régimen y qué seguro le toca a cada trabajador lo dice su usuario; las
 * tasas viven en configuraciones_planilla, por empresa y con override por
 * agencia.
 */
final class ConfiguracionPlanillaService
{
    /**
     * Tasas vigentes para una empresa/agencia. Si la agencia no tiene fila
     * propia, cae en la de la empresa (agencia_id nulo) y, si tampoco existe,
     * usa los defaults de la migracion: asi el modulo funciona sin configurar
     * nada.
     */
    public function resolverPara(?int $empresaId, ?int $agenciaId): ConfiguracionPlanilla
    {
        $configuracion = ConfiguracionPlanilla::query()
            ->when(
                $agenciaId,
                fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('agencia_id', $agenciaId)->orWhereNull('agencia_id')),
                fn (Builder $q) => $q->whereNull('agencia_id'),
            )
            // La fila de la agencia gana sobre la default de la empresa.
            ->orderByRaw('CASE WHEN agencia_id IS NULL THEN 1 ELSE 0 END')
            ->first();

        if (! $configuracion) {
            // Se crea vacia y se recarga: los default de la migracion (AFP 11.37%,
            // ONP 13%, EsSalud 9%, SIS S/ 15) los aplica MySQL al insertar, y Eloquent
            // no los trae en la instancia recien creada: sin el refresh, leer la
            // tasa daria null.
            $configuracion = ConfiguracionPlanilla::query()->create([
                'empresa_id' => $empresaId,
                'agencia_id' => $agenciaId,
            ])->refresh();
        }

        return $configuracion;
    }

    /**
     * Descuento previsional del trabajador sobre su sueldo básico: AFP
     * (11.37%) u ONP (13%), regímenes alternantes — quien está en AFP no aporta
     * a ONP. El régimen viene del usuario (User::sistema_pension).
     *
     * @return array{onp: string, afp: string, essalud_trabajador: string, tributos_trabajador: string}
     */
    public function tributosTrabajador(ConfiguracionPlanilla $configuracion, string $sueldoBasico, bool $esAfp): array
    {
        $onp = $esAfp ? '0.00' : $this->porcentaje($configuracion->onp_trabajador, $sueldoBasico);
        $afp = $esAfp ? $this->porcentaje($configuracion->afp_trabajador, $sueldoBasico) : '0.00';

        return [
            'onp' => $onp,
            'afp' => $afp,
            'essalud_trabajador' => '0.00',
            'tributos_trabajador' => bcadd($onp, $afp, 2),
        ];
    }

    /**
     * Aporte del empleador por el seguro de salud del trabajador: EsSalud (9%
     * del sueldo básico) o SIS (monto fijo, S/ 15). No se resta del sueldo:
     * es costo de la empresa, pero se reporta junto al resto de la planilla.
     * El seguro viene del usuario (User::seguro_salud).
     *
     * @return array{essalud_empleador: string, sis: string, cts: string, aportes_empleador: string}
     */
    public function aportesEmpleador(ConfiguracionPlanilla $configuracion, string $sueldoBasico, bool $esSis): array
    {
        $essalud = $esSis ? '0.00' : $this->porcentaje($configuracion->essalud_empleador, $sueldoBasico);
        $sis = $esSis ? bcadd((string) $configuracion->sis_empleador, '0', 2) : '0.00';

        return [
            'essalud_empleador' => $essalud,
            'sis' => $sis,
            'cts' => '0.00',
            'aportes_empleador' => bcadd($essalud, $sis, 2),
        ];
    }

    /**
     * Porcentaje de un monto, redondeado a los 2 decimales que maneja la
     * planilla.
     *
     * Se divide con escala 6 y se multiplica con escala alta (8) antes de
     * redondear: si se truncara cada operacion a 2 decimales, un monto como
     * 94.129 quedaria en 94.12 en vez de 94.13 y el total caeria un centavo.
     * El redondeo es half-up, no el truncado
     * de bcmath, que es lo que corresponde a un monto de dinero.
     */
    private function porcentaje(string $porcentaje, string $monto): string
    {
        $exacto = bcmul($monto, bcdiv($porcentaje, '100', 8), 8);

        return bcadd($exacto, '0.005', 2);
    }
}
