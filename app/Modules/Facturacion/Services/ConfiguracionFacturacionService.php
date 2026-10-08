<?php

namespace App\Modules\Facturacion\Services;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use DomainException;

/**
 * Resolución de los parámetros de facturación de una empresa.
 *
 * A diferencia de ConfiguracionVentaService / ConfiguracionCreditoService, acá
 * NO hay eje agencia: el IGV, la moneda y el modo de entorno son de la
 * empresa, no del establecimiento. El punto de emisión afecta solo a qué serie
 * se usa, y eso lo resuelve SerieService.
 */
final class ConfiguracionFacturacionService
{
    /**
     * @throws DomainException si la empresa todavía no configuró facturación —
     *                         es preferible a emitir con valores por defecto silenciosos.
     */
    public function resolverPara(Empresa $empresa): ConfiguracionFacturacion
    {
        $configuracion = ConfiguracionFacturacion::query()
            ->where('empresa_id', $empresa->id)
            ->first();

        if (! $configuracion) {
            throw new DomainException(
                'Esta empresa no tiene configuración de facturación. Debe configurarse antes de emitir comprobantes.'
            );
        }

        return $configuracion;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Empresa $empresa, array $datos): ConfiguracionFacturacion
    {
        return ConfiguracionFacturacion::query()->updateOrCreate(
            ['empresa_id' => $empresa->id],
            $datos
        );
    }
}
