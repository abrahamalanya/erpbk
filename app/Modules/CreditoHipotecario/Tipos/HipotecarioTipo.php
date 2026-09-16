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
            'tipo_interes' => $datos['tipo_interes'] ?? 'simple',
        ];
    }

    /**
     * El sticker se pega sobre el bien/vehículo físico en tienda; hipotecario
     * no tiene un artículo que etiquetar (la garantía es el inmueble, que no
     * pasa por tienda) — usa ficha socioeconómica/notificación de pago/aviso
     * prejudicial/expediente en su lugar.
     */
    public function generaStickerGarantia(): bool
    {
        return false;
    }
}
