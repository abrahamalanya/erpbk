<?php

namespace App\Modules\Planilla\Services;

use App\Modules\Planilla\Models\ConfiguracionPlanilla;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tasas y base imponible de la planilla.
 *
 * Se calcula automaticamente lo que es una tasa fija y conocida: ONP, Essalud
 * del trabajador, Essalud del empleador y CTS.
 *
 * La AFP NO entra por aca: su monto se declara a mano por linea (ver
 * PlanillaService::tributosTrabajador()). Cada trabajador esta en una AFP
 * distinta, la tasa cambia por institution y hay meses con aportes
 * extraordinarios, asi que una tasa global en la configuracion mentiria.
 *
 * Base imponible: los aportes del trabajador (ONP + AFP + Essalud) se calculan
 * sobre el sueldo del mes sin bonificaciones, que es el tratamiento peruano
 * para Essalud y ONP; el aporte del empleador (Essalud + CTS) se calcula sobre
 * el sueldo completo. La base se separa en PlanillaService al armar cada linea.
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
            // Se crea vacia y se recarga: los default de la migracion (ONP 3%,
            // Essalud 4%/5%, CTS 8.33%) los aplica MySQL al insertar, y Eloquent
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
     * Aportes del trabajador. ONP y Essalud salen de la tasa configurada; la AFP
     * entra como el monto declarado a mano ($afpDeclarado).
     *
     * ONP y AFP son regimenes de pension alternantes: quien esta en AFP no
     * aporta a ONP. El `es_afp` de la linea sigue mandando sobre eso, porque un
     * monto manual de AFP en 0 no permite distinguir "no esta en AFP" de "esta
     * en AFP y este mes no aporta".
     *
     * @return array{onp: string, afp: string, essalud_trabajador: string, tributos_trabajador: string}
     */
    public function tributosTrabajador(ConfiguracionPlanilla $configuracion, string $baseImponible, bool $esAfp, string $afpDeclarado = '0'): array
    {
        $onp = $esAfp ? '0.00' : $this->porcentaje($configuracion->onp_trabajador, $baseImponible);
        $afp = $esAfp ? bcadd($afpDeclarado, '0', 2) : '0.00';
        $essalud = $this->porcentaje($configuracion->essalud_trabajador, $baseImponible);

        return [
            'onp' => $onp,
            'afp' => $afp,
            'essalud_trabajador' => $essalud,
            'tributos_trabajador' => bcadd($onp, bcadd($afp, $essalud, 2), 2),
        ];
    }

    /**
     * Aporte del empleador: Essalud (sobre el sueldo completo) mas CTS. No se
     * resta del sueldo: es costo de la empresa, pero se reporta junto al resto
     * de la planilla.
     *
     * @return array{essalud_empleador: string, cts: string, aportes_empleador: string}
     */
    public function aportesEmpleador(ConfiguracionPlanilla $configuracion, string $remuneracion): array
    {
        $essalud = $this->porcentaje($configuracion->essalud_empleador, $remuneracion);
        $cts = $this->porcentaje($configuracion->cts_empleador, $remuneracion);

        return [
            'essalud_empleador' => $essalud,
            'cts' => $cts,
            'aportes_empleador' => bcadd($essalud, $cts, 2),
        ];
    }

    /**
     * Porcentaje de un monto, redondeado a los 2 decimales que maneja la
     * planilla.
     *
     * Se divide con escala 6 y se multiplica con escala alta (8) antes de
     * redondear: si se truncara cada operacion a 2 decimales, el CTS al 8.33%
     * de 1130 daria 94.12 en vez de 94.13 (1130 * 0.0833 = 94.129) y el total
     * del empleador caeria un centavo. El redondeo es half-up, no el truncado
     * de bcmath, que es lo que corresponde a un monto de dinero.
     */
    private function porcentaje(string $porcentaje, string $monto): string
    {
        $exacto = bcmul($monto, bcdiv($porcentaje, '100', 8), 8);

        return bcadd($exacto, '0.005', 2);
    }
}
