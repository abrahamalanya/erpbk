<?php

namespace App\Modules\CreditoPrendario\Services;

use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use DomainException;

final class ConfiguracionCreditoPrendarioService
{
    /**
     * Resolves the effective configuration for an agencia: an
     * agencia-specific override if one exists, otherwise the empresa-wide
     * default.
     */
    public function resolverPara(Agencia $agencia): ConfiguracionCreditoPrendario
    {
        $override = ConfiguracionCreditoPrendario::query()
            ->where('agencia_id', $agencia->id)
            ->first();

        if ($override) {
            return $override;
        }

        $default = ConfiguracionCreditoPrendario::query()
            ->where('empresa_id', $agencia->empresa_id)
            ->whereNull('agencia_id')
            ->first();

        if ($default) {
            return $default;
        }

        throw new DomainException('No hay configuración de créditos prendarios en esta empresa. Debe configurarse antes de registrar créditos.');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Empresa $empresa, ?Agencia $agencia, array $datos): ConfiguracionCreditoPrendario
    {
        return ConfiguracionCreditoPrendario::query()->updateOrCreate(
            ['empresa_id' => $empresa->id, 'agencia_id' => $agencia?->id],
            $datos
        );
    }
}
