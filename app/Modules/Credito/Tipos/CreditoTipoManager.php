<?php

namespace App\Modules\Credito\Tipos;

use App\Modules\Credito\Models\Credito;
use DomainException;

/**
 * Registry of the available CreditoTipo strategies, wired up in
 * AppServiceProvider. The shared engine resolves a strategy either from an
 * explicit clave (registrar()) or from a crédito's tipo_credito column
 * (cron, cobros, envío a tienda).
 */
final class CreditoTipoManager
{
    /**
     * @var array<string, CreditoTipo>
     */
    private array $tipos = [];

    public function registrar(CreditoTipo $tipo): void
    {
        $this->tipos[$tipo->clave()] = $tipo;
    }

    public function para(string $clave): CreditoTipo
    {
        return $this->tipos[$clave]
            ?? throw new DomainException("Tipo de crédito desconocido: {$clave}");
    }

    public function paraCredito(Credito $credito): CreditoTipo
    {
        return $this->para($credito->tipo_credito ?? 'prendario');
    }

    /**
     * @return list<string>
     */
    public function claves(): array
    {
        return array_keys($this->tipos);
    }
}
