<?php

namespace App\Modules\Qr\Http\Controllers;

use App\Modules\Qr\Http\Requests\GenerarQrRequest;
use App\Modules\Qr\Models\QrGenerado;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class QrController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $registros = QrGenerado::query()->orderByDesc('id')->paginate(15);
        $registros->through(fn (QrGenerado $qr): array => $this->datos($qr, $this->generarPng($qr->url)));

        return $this->successResponse($registros);
    }

    public function store(GenerarQrRequest $request): JsonResponse
    {
        $png = $this->generarPng($request->url());
        $qr = $this->registrar($request);

        return $this->successResponse($this->datos($qr, $png), 'QR generado y guardado', 201);
    }

    /** Mantiene el contrato PNG de las versiones anteriores de web y app. */
    public function generar(GenerarQrRequest $request): Response
    {
        $png = $this->generarPng($request->url());
        $qr = $this->registrar($request);

        return $this->respuestaPng($qr, $png);
    }

    public function png(QrGenerado $qrGenerado): Response
    {
        return $this->respuestaPng($qrGenerado, $this->generarPng($qrGenerado->url));
    }

    public function destroy(QrGenerado $qrGenerado): JsonResponse
    {
        $qrGenerado->delete();

        return $this->successResponse(null, 'QR eliminado del historial');
    }

    private function generarPng(string $url): string
    {
        $writer = new PngWriter;
        $qrCode = new QrCode(data: $url, size: 400, margin: 10);

        return $writer->write($qrCode)->getString();
    }

    private function registrar(GenerarQrRequest $request): QrGenerado
    {
        return QrGenerado::query()->create([
            'url' => $request->url(),
            'user_id' => $request->user()->id,
            'generado_por' => trim($request->user()->nombre.' '.$request->user()->apellido),
        ]);
    }

    /** @return array<string, mixed> */
    private function datos(QrGenerado $qr, string $png): array
    {
        return [...$qr->toArray(), 'imagen_png' => 'data:image/png;base64,'.base64_encode($png)];
    }

    private function respuestaPng(QrGenerado $qr, string $png): Response
    {
        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="qr-'.$qr->id.'.png"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
