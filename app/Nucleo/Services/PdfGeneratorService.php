<?php

namespace App\Nucleo\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class PdfGeneratorService
{
    /**
     * Renders a Blade view to PDF and streams it back inline — nothing is
     * written to disk, the document is regenerated fresh on every request.
     *
     * @param  array<string, mixed>  $datos
     */
    public function renderizarDesdeVista(string $vista, array $datos): Response
    {
        return Pdf::loadView($vista, $datos)->stream();
    }
}
