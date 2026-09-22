<?php

namespace App\Modules\Venta\Services;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Venta\Models\ConfiguracionVenta;
use DomainException;

final class ConfiguracionVentaService
{
    /**
     * Resolves the effective configuration for an agencia: an
     * agencia-specific override if one exists, otherwise the empresa-wide
     * default. Mirror de ConfiguracionCreditoService::resolverPara().
     */
    public function resolverPara(Agencia $agencia): ConfiguracionVenta
    {
        $override = ConfiguracionVenta::query()
            ->where('agencia_id', $agencia->id)
            ->first();

        if ($override) {
            return $override;
        }

        $default = ConfiguracionVenta::query()
            ->where('empresa_id', $agencia->empresa_id)
            ->whereNull('agencia_id')
            ->first();

        if ($default) {
            return $default;
        }

        throw new DomainException('No hay configuración de ventas en esta empresa. Debe configurarse antes de vender a crédito.');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Empresa $empresa, ?Agencia $agencia, array $datos): ConfiguracionVenta
    {
        return ConfiguracionVenta::query()->updateOrCreate(
            ['empresa_id' => $empresa->id, 'agencia_id' => $agencia?->id],
            $datos
        );
    }

    /**
     * Borra un override de agencia (la agencia vuelve a heredar el default
     * de la empresa) o la fila default de la empresa misma —
     * ConfiguracionVentaPolicy::delete() restringe esto último a
     * administrador_general.
     */
    public function eliminar(ConfiguracionVenta $configuracion): void
    {
        $configuracion->delete();
    }
}
