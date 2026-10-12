<?php

namespace App\Modules\Facturacion\Services\Sunat;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\CredencialSunat;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para comunicación de baja (anulación) de un comprobante ya
 * aceptado por SUNAT.
 *
 * PENDIENTE: esta clase apuntaba a `Greenter\Ws\Services\SunatSendService` y
 * `Greenter\Ws\SummaryResponse`, ninguna de las dos existe en los paquetes
 * instalados (verificado: `grep -rn "class SunatSendService\|class
 * SummaryResponse" vendor/greenter` no encuentra nada) — fatalaba al cargar
 * la clase. La comunicación de baja es, igual que el resumen diario
 * (ver SunatSoapClient::enviarResumenDiario()), un `SummarySender::send()`
 * con el XML "Comunicación de Baja" construido con
 * `Greenter\Xml\Builder\VoidedBuilder`/un modelo `Summary` marcado como baja
 * — ese paso de construcción del XML nunca se implementó. Hasta que se
 * resuelva, ambos métodos devuelven error explícito en vez de fatalar o
 * fingir éxito.
 */
final class SunatBajaClient
{
    public function __construct(
        private readonly CredencialSunat $credencial,
        private readonly string $modo,
    ) {}

    /**
     * Comunica la baja de un comprobante ya aceptado.
     *
     * @return array{
     *     exito: bool,
     *     ticket: ?string,
     *     cdr_codigo: ?string,
     *     cdr_descripcion: ?string,
     *     cdr_xml: ?string,
     *     error: ?string
     * }
     */
    public function comunicarBaja(Comprobante $comprobante, string $motivo): array
    {
        Log::warning('Comunicación de baja solicitada, pero no está implementada', [
            'comprobante' => $comprobante->numero,
            'empresa' => $this->credencial->empresa_id,
        ]);

        return [
            'exito' => false,
            'ticket' => null,
            'cdr_codigo' => null,
            'cdr_descripcion' => null,
            'cdr_xml' => null,
            'error' => 'Comunicación de baja no implementada: falta construir el XML de baja (VoidedBuilder). Ver SunatBajaClient.',
        ];
    }

    /**
     * Consulta el CDR de una comunicación de baja o resumen diario ya enviado.
     */
    public function consultarCdrResumen(string $ticket): array
    {
        Log::warning('Consulta de CDR de baja/resumen solicitada, pero no está implementada', ['ticket' => $ticket]);

        return [
            'exito' => false,
            'cdr_codigo' => null,
            'cdr_descripcion' => null,
            'cdr_xml' => null,
            'cdr_digests' => null,
            'error' => 'Consulta de CDR de baja/resumen no implementada: greenter/ws no expone esa operación por ticket. Ver SunatSoapClient::consultarResumenDiario().',
        ];
    }
}
