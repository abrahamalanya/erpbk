<?php

namespace App\Modules\Facturacion\Services;

use App\Modules\Facturacion\Models\Comprobante;
use App\Nucleo\Services\PdfGeneratorService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Representación impresa del comprobante.
 *
 * Sigue el patrón nunca-guardado de DocumentoVentaService: el PDF se
 * re-renderiza al vuelo desde la fila `comprobantes`, que es un snapshot
 * inmutable de la emisión. Nunca se guarda en disco ni se regenera desde los
 * datos actuales del cliente o la empresa.
 *
 * El contenido es el que SUNAT exige en la representación impresa: datos del
 * emisor, serie-correlativo, detalle con IGV por línea, total en letras y el
 * código de seguridad cuando ya llegó el CDR.
 */
final class ComprobantePdfService
{
    public function __construct(private readonly PdfGeneratorService $pdfGenerator) {}

    public function renderizar(Comprobante $comprobante): Response
    {
        $comprobante->loadMissing(['detalles', 'serieComprobante']);

        return $this->pdfGenerator->renderizarDesdeVista('modules.facturacion.documentos.comprobante', [
            'comprobante' => $comprobante,
        ]);
    }
}
