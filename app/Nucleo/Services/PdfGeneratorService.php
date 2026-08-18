<?php

namespace App\Nucleo\Services;

use Barryvdh\DomPDF\Facade\Pdf;

class PdfGeneratorService
{
    /**
     * Renders a Blade view to PDF and stores it on the given disk.
     *
     * @param  array<string, mixed>  $datos
     */
    public function generarDesdeVista(string $vista, array $datos, string $rutaDestino, string $disk = 'public'): string
    {
        Pdf::loadView($vista, $datos)->save($rutaDestino, $disk);

        return $rutaDestino;
    }
}
