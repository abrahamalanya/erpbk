<?php

namespace App\Nucleo\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Downscales and re-encodes an uploaded foto (disco "public") en un data URI
 * embebible en un <img> de un PDF (dompdf no puede leer rutas de Storage
 * directamente). Usado por los documentos de Credito y Venta para mostrar la
 * firma de la empresa (Empresa::firma_path) donde corresponda, y por el
 * contrato prendario/vehicular/hipotecario para las fotos de garantía.
 */
final class FotoDataUriService
{
    /**
     * @param  int  $maxAncho  Ancho máximo en px; imágenes más anchas se reducen manteniendo proporción.
     */
    public function generar(?string $path, int $maxAncho = 900): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $absoluto = Storage::disk('public')->path($path);
        $info = @getimagesize($absoluto);

        if (! $info) {
            return null;
        }

        [$ancho, $alto, $tipo] = $info;

        $origen = match ($tipo) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($absoluto),
            IMAGETYPE_PNG => imagecreatefrompng($absoluto),
            default => null,
        };

        if (! $origen) {
            return null;
        }

        if ($ancho > $maxAncho) {
            $nuevoAlto = (int) round($alto * ($maxAncho / $ancho));
            $redimensionada = imagecreatetruecolor($maxAncho, $nuevoAlto);
            imagecopyresampled($redimensionada, $origen, 0, 0, 0, 0, $maxAncho, $nuevoAlto, $ancho, $alto);
            imagedestroy($origen);
            $origen = $redimensionada;
        }

        ob_start();
        imagejpeg($origen, null, 75);
        $bytes = ob_get_clean();
        imagedestroy($origen);

        return 'data:image/jpeg;base64,'.base64_encode($bytes);
    }
}
