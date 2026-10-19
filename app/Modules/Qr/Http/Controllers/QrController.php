<?php

namespace App\Modules\Qr\Http\Controllers;

use App\Modules\Qr\Http\Requests\GenerarQrRequest;
use App\Nucleo\Http\Controllers\Controller;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;

/**
 * Herramienta de Sistemas: genera un QR (PNG) a partir de una URL arbitraria,
 * para imprimir o compartir. No pertenece a ningún dominio de negocio, así
 * que no tiene modelo ni Service propio — reusa las mismas clases que
 * DocumentoCreditoService::qrDataUri() (QR de sticker/fotocheck), pero
 * devolviendo los bytes crudos en vez de un data URI embebido en un PDF.
 */
class QrController extends Controller
{
    public function generar(GenerarQrRequest $request): Response
    {
        abort_unless($request->user()->hasAnyRole(['sistemas', 'administrador_general']), 403);

        $writer = new PngWriter;
        $qrCode = new QrCode(data: $request->url(), size: 400, margin: 10);

        return response($writer->write($qrCode)->getString(), 200, [
            'Content-Type' => 'image/png',
        ]);
    }
}
