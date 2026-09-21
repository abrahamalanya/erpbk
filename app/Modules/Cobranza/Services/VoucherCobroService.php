<?php

namespace App\Modules\Cobranza\Services;

use App\Modules\Cobranza\Models\Cobro;

/**
 * Texto plano del voucher de un cobro (para compartirlo, por ejemplo por
 * WhatsApp). El PDF lo genera DocumentoCreditoService::renderizar() a partir
 * del voucher de pago del cobro; este texto se arma con los mismos datos del
 * Cobro para que cualquier cliente (web o app) comparta exactamente lo mismo.
 */
final class VoucherCobroService
{
    /**
     * @var array<string, string>
     */
    private const OPERACIONES = [
        'refrendo' => 'Refrendo',
        'adenda' => 'Adenda',
        'liquidacion' => 'Liquidación',
        'refinanciamiento' => 'Refinanciamiento',
        'pago_cuota' => 'Pago de cuota',
        'pago_cuotas_diario' => 'Pago de cuotas',
    ];

    public function texto(Cobro $cobro): string
    {
        $cobro->loadMissing(['cliente', 'credito.empresa', 'credito.agencia', 'registradoPor']);

        $credito = $cobro->credito;

        $lineas = array_filter([
            strtoupper((string) ($credito->empresa->razon_social ?: $credito->empresa->nombre)),
            $credito->agencia?->nombre,
            'COMPROBANTE DE COBRO',
            "#{$cobro->id} · {$cobro->created_at->format('d/m/Y H:i')}",
        ], fn (?string $linea): bool => filled($linea));

        $lineas[] = '';

        foreach ($this->filas($cobro) as $etiqueta => $valor) {
            $lineas[] = "{$etiqueta}: {$valor}";
        }

        if ($cobro->estado === 'anulado') {
            $lineas[] = '';
            $lineas[] = '*** ANULADO ***';

            if (filled($cobro->motivo_anulacion)) {
                $lineas[] = "Motivo: {$cobro->motivo_anulacion}";
            }
        }

        return implode("\n", $lineas);
    }

    /**
     * @return array<string, string>
     */
    private function filas(Cobro $cobro): array
    {
        $cliente = $cobro->cliente;
        $credito = $cobro->credito;

        $filas = [
            'Cliente' => $cliente ? strtoupper($cliente->nombre.' '.$cliente->apellido) : "#{$cobro->cliente_id}",
        ];

        if (filled($cliente?->numero_documento)) {
            $filas['Documento'] = $cliente->numero_documento;
        }

        $filas['Crédito'] = "{$credito->codigo} (".ucfirst($credito->tipo_credito).')';
        $filas['Operación'] = self::OPERACIONES[$cobro->operacion] ?? ucfirst(str_replace('_', ' ', $cobro->operacion));
        $filas['Monto pagado'] = $this->monto($cobro->monto_pagado);

        if ((float) $cobro->interes > 0) {
            $filas['Interés'] = $this->monto($cobro->interes);
        }

        if ((float) $cobro->mora > 0) {
            $filas['Mora'] = $this->monto($cobro->mora);
        }

        if ((float) $cobro->descuento > 0) {
            $filas['Descuento'] = '-'.$this->monto($cobro->descuento);
        }

        if ((float) $cobro->vuelto > 0) {
            $filas['Vuelto'] = $this->monto($cobro->vuelto);
        }

        $filas['Medio de pago'] = ucfirst($cobro->medio);
        $filas['Atendido por'] = $cobro->registradoPor ? trim($cobro->registradoPor->nombre.' '.$cobro->registradoPor->apellido) : '—';

        return $filas;
    }

    private function monto(mixed $valor): string
    {
        return 'S/ '.number_format((float) $valor, 2);
    }
}
