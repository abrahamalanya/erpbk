<?php

namespace App\Nucleo\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelGeneratorService
{
    /**
     * Arma un .xlsx de una sola hoja (encabezados en negrita + filas,
     * columnas autoajustadas) y lo transmite como descarga — igual de
     * genérico que PdfGeneratorService::renderizarDesdeVista(), nada se
     * escribe a disco.
     *
     * $bolitas pinte el fondo de celdas concretas — es el equivalente .xlsx
     * de la bolita de color del PDF, que en una hoja de cálculo no se puede
     * dibujar como círculo. El texto se pone en blanco o negro según lo
     * oscuro del fondo para que siempre se lea.
     *
     * @param  list<string>  $encabezados
     * @param  list<list<string|int|float>>  $filas
     * @param  array<int, array<string, string>>  $bolitas  [índice de fila][letra de columna] => hex
     */
    public function generarDesdeFilas(string $titulo, array $encabezados, array $filas, array $bolitas = []): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reporte');

        $sheet->fromArray($encabezados, null, 'A1');
        $sheet->fromArray($filas, null, 'A2');

        $ultimaColumna = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$ultimaColumna}1")->getFont()->setBold(true);

        foreach ($sheet->getColumnIterator() as $columna) {
            $sheet->getColumnDimension($columna->getColumnIndex())->setAutoSize(true);
        }

        $this->pintarBolitas($sheet, $bolitas);

        $writer = new Xlsx($spreadsheet);
        $nombreArchivo = $titulo.'.xlsx';

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $bolitas
     */
    private function pintarBolitas(Worksheet $sheet, array $bolitas): void
    {
        foreach ($bolitas as $indiceFila => $columnas) {
            // La fila 0 de $filas es la fila 2 de la hoja (la 1 son encabezados).
            $fila = $indiceFila + 2;

            foreach ($columnas as $letra => $color) {
                $estilo = $sheet->getStyle("{$letra}{$fila}");

                $estilo->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB($this->argb($color));

                $estilo->getFont()
                    ->setBold(true)
                    ->getColor()
                    ->setARGB($this->argb($this->colorDeTextoSobre($color)));
            }
        }
    }

    /**
     * Normaliza '#f97316' a 'F97316': PhpSpreadsheet valida el ARGB con
     * mayúsculas y sin el '#', y guardarlo tal cual lo deja en minúsculas.
     */
    private function argb(string $hex): string
    {
        return strtoupper(ltrim($hex, '#'));
    }

    /**
     * Negro o blanco según la luminancia del fondo (fórmula perceptual de
     * WCAG), para que el texto de una bolita no se vuelva ilegible.
     */
    private function colorDeTextoSobre(string $hex): string
    {
        $hex = strtolower(ltrim($hex, '#'));

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6) {
            return '000000';
        }

        $canales = [];
        foreach ([0, 2, 4] as $offset) {
            $canales[] = hexdec(substr($hex, $offset, 2)) / 255;
        }

        [$rojo, $verde, $azul] = array_map(
            static fn (float $canal): float => $canal <= 0.03928
                ? $canal / 12.92
                : (($canal + 0.055) / 1.055) ** 2.4,
            $canales,
        );

        $luminancia = 0.2126 * $rojo + 0.7152 * $verde + 0.0722 * $azul;

        return $luminancia > 0.179 ? '000000' : 'FFFFFF';
    }
}
