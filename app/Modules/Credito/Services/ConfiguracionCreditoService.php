<?php

namespace App\Modules\Credito\Services;

use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use DomainException;

final class ConfiguracionCreditoService
{
    /**
     * Resolves the effective configuration for an agencia and tipo de
     * crédito: an agencia-specific override if one exists, otherwise the
     * empresa-wide default.
     */
    public function resolverPara(Agencia $agencia, string $tipoCredito = 'prendario'): ConfiguracionCredito
    {
        $override = ConfiguracionCredito::query()
            ->where('agencia_id', $agencia->id)
            ->where('tipo_credito', $tipoCredito)
            ->first();

        if ($override) {
            return $override;
        }

        $default = ConfiguracionCredito::query()
            ->where('empresa_id', $agencia->empresa_id)
            ->where('tipo_credito', $tipoCredito)
            ->whereNull('agencia_id')
            ->first();

        if ($default) {
            return $default;
        }

        throw new DomainException("No hay configuración de créditos ({$tipoCredito}) en esta empresa. Debe configurarse antes de registrar créditos.");
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Empresa $empresa, ?Agencia $agencia, array $datos, string $tipoCredito = 'prendario'): ConfiguracionCredito
    {
        return ConfiguracionCredito::query()->updateOrCreate(
            ['empresa_id' => $empresa->id, 'agencia_id' => $agencia?->id, 'tipo_credito' => $tipoCredito],
            $datos
        );
    }

    /**
     * Borra un override de agencia (la agencia vuelve a heredar el default de
     * la empresa para ese tipo) o la fila default de la empresa misma —
     * ConfiguracionCreditoPolicy::delete() ya restringe esto último a
     * administrador_general, porque deja a la empresa sin configuración
     * resoluble para ese tipo hasta que se registre una nueva (resolverPara()
     * lanzará DomainException al intentar registrar un crédito mientras
     * tanto).
     */
    public function eliminar(ConfiguracionCredito $configuracion): void
    {
        $configuracion->delete();
    }
}
