<?php

namespace App\Modules\CreditoHipotecario\Tipos;

use App\Modules\Credito\Tipos\CreditoTipoSupervisado;
use App\Modules\CreditoHipotecario\Models\Inmueble;

/**
 * Crédito hipotecario: garantía es un Inmueble (partida registral SUNARP).
 * Comparte con vehicular los requisitos de garantía formal
 * (dirección/referencia del cliente, supervisado por, conformidad del
 * notario/abogado previa a la tienda) vía CreditoTipoSupervisado.
 */
final class HipotecarioTipo extends CreditoTipoSupervisado
{
    public function clave(): string
    {
        return 'hipotecario';
    }

    public function garantiaModelo(): string
    {
        return Inmueble::class;
    }

    protected function etiqueta(): string
    {
        return 'hipotecario';
    }

    protected function moduloVistas(): string
    {
        return 'credito-hipotecario';
    }

    /**
     * Además del supervisado_por que comparte con vehicular, el crédito
     * hipotecario persiste el aval (garante).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function atributosExtra(array $datos): array
    {
        return [
            ...parent::atributosExtra($datos),
            'aval_id' => $datos['aval_id'] ?? null,
            'aval_2_id' => $datos['aval_2_id'] ?? null,
        ];
    }
}
