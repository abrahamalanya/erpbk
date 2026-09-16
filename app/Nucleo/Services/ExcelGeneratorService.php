<?php

namespace App\Nucleo\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
     * @param  list<string>  $encabezados
     * @param  list<list<string|int|float>>  $filas
     */
    public function generarDesdeFilas(string $titulo, array $encabezados, array $filas): StreamedResponse
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

        $writer = new Xlsx($spreadsheet);
        $nombreArchivo = $titulo.'.xlsx';

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $nombreArchivo, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
