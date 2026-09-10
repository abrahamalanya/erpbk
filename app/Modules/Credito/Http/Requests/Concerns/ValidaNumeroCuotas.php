<?php

namespace App\Modules\Credito\Http\Requests\Concerns;

use App\Modules\Credito\Services\ConfiguracionCreditoService;
use Closure;
use DomainException;

/**
 * Regla compartida por los tres Store requests de crédito: `numero_cuotas`
 * es opcional (null ⇒ el crédito usa el default por tipo_cuota al
 * desembolsar), y cuando viene debe ser >= 1 y no superar el `max_cuotas`
 * configurado para ese tipo de crédito en la agencia del usuario.
 */
trait ValidaNumeroCuotas
{
    /**
     * @return array<int, mixed>
     */
    protected function reglasNumeroCuotas(string $tipoCredito): array
    {
        return [
            'nullable', 'integer', 'min:1',
            function (string $attribute, mixed $value, Closure $fail) use ($tipoCredito): void {
                $agencia = $this->user()?->agencia;

                if ($agencia === null) {
                    return;
                }

                try {
                    $max = app(ConfiguracionCreditoService::class)->resolverPara($agencia, $tipoCredito)->max_cuotas;
                } catch (DomainException) {
                    return;
                }

                if ((int) $value > $max) {
                    $fail("El número de cuotas no puede superar {$max} para este tipo de crédito.");
                }
            },
        ];
    }
}
