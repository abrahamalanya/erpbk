<?php

namespace App\Modules\Facturacion\Exports;

use App\Modules\Facturacion\Models\Comprobante;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export de comprobantes emitidos para Libro de Ventas / Reporte de boletas.
 *
 * Usa PhpSpreadsheet directamente (ya instalado como dependencia de dompdf)
 * para evitar problemas de compatibilidad con maatwebsite/excel en PHP 8.2.
 */
class ComprobantesEmitidosExport
{
    public function __construct(
        private readonly int $empresaId,
        private readonly \Carbon\CarbonImmutable $desde,
        private readonly \Carbon\CarbonImmutable $hasta,
        private readonly ?int $agenciaId = null,
    ) {}

    /**
     * Genera y devuelve una StreamedResponse con el archivo XLSX.
     */
    public function download(string $filename): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet();

        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

        return $response;
    }

    private function buildSpreadsheet(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('LIBRO VENTAS');

        // Headers
        $headers = [
            'FECHA EMISIÓN',
            'TIPO COMPROBANTE',
            'SERIE',
            'NÚMERO',
            'TIPO DOC. RECEPTOR',
            'NÚMERO DOC. RECEPTOR',
            'NOMBRE/RAZÓN SOCIAL RECEPTOR',
            'DOMICILIO RECEPTOR',
            'BASE IMPONIBLE GRAVADA',
            'BASE IMPONIBLE EXONERADA',
            'BASE IMPONIBLE INAFECTA',
            'IGV',
            'ISC',
            'OTROS TRIBUTOS',
            'TOTAL',
            'MONEDA',
            'TIPO CAMBIO',
            'ESTADO',
            'CÓDIGO SEGURIDAD',
            'OBSERVACIONES',
        ];

        // Style for header row
        $headerStyle = [
            'font' => ['bold' => true, 'size' => 10],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E0E0E0']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
        ];

        // Write headers
        foreach ($headers as $colIndex => $header) {
            $cell = $sheet->getCellByColumnAndRow($colIndex + 1, 1);
            $cell->setValue($header);
        }
        $sheet->getStyle('A1:T1')->applyFromArray($headerStyle);

        // Data
        $comprobantes = $this->getComprobantes();
        $row = 2;

        foreach ($comprobantes as $comprobante) {
            $data = $this->mapComprobante($comprobante);

            foreach ($data as $colIndex => $value) {
                $cell = $sheet->getCellByColumnAndRow($colIndex + 1, $row);
                $cell->setValue($value);
            }

            // Style data row
            $sheet->getStyle("A{$row}:T{$row}")->applyFromArray([
                'font' => ['size' => 9],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN]],
            ]);

            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'T') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * @return Collection<int, Comprobante>
     */
    private function getComprobantes(): Collection
    {
        return Comprobante::query()
            ->where('empresa_id', $this->empresaId)
            ->when($this->agenciaId !== null, fn ($q) => $q->where('agencia_id', $this->agenciaId))
            ->whereBetween('fecha_emision', [$this->desde, $this->hasta])
            ->orderBy('fecha_emision')
            ->orderBy('correlativo')
            ->get();
    }

    private function mapComprobante(Comprobante $comprobante): array
    {
        $tipoNombre = match ($comprobante->tipo_documento) {
            '01' => 'FACTURA',
            '03' => 'BOLETA',
            '07' => 'NOTA DE CRÉDITO',
            default => $comprobante->tipo_documento,
        };

        $tipoDocReceptor = match ($comprobante->receptor_tipo_documento) {
            'dni' => 'DNI',
            'ruc' => 'RUC',
            'ce' => 'CE',
            'pasaporte' => 'PASAPORTE',
            default => strtoupper($comprobante->receptor_tipo_documento),
        };

        $baseGravada = '0.00';
        $baseExonerada = '0.00';
        $baseInafecta = '0.00';

        foreach ($comprobante->detalles as $detalle) {
            $cat = $detalle->categoria_tributo->value ?? $detalle->categoria_tributo;
            $valorVenta = (string) $detalle->valor_venta;
            switch ($cat) {
                case 'S':
                    $baseGravada = bcadd($baseGravada, $valorVenta, 2);
                    break;
                case 'G':
                    $baseExonerada = bcadd($baseExonerada, $valorVenta, 2);
                    break;
                case 'E':
                    $baseInafecta = bcadd($baseInafecta, $valorVenta, 2);
                    break;
                default:
                    $baseGravada = bcadd($baseGravada, $valorVenta, 2);
            }
        }

        return [
            $comprobante->fecha_emision->format('d/m/Y'),
            $tipoNombre,
            $comprobante->serie,
            str_pad((string) $comprobante->correlativo, 8, '0', STR_PAD_LEFT),
            $tipoDocReceptor,
            $comprobante->receptor_numero_documento,
            $comprobante->receptor_nombre,
            $comprobante->receptor_domicilio ?? '-',
            $baseGravada,
            $baseExonerada,
            $baseInafecta,
            (string) $comprobante->igv,
            '0.00',
            '0.00',
            (string) $comprobante->total,
            $comprobante->moneda === 'PEN' ? 'PEN' : 'USD',
            $comprobante->moneda === 'PEN' ? '1.000' : '0.000',
            $comprobante->estado->value,
            $comprobante->codigo_seguridad ?? '-',
            $comprobante->observaciones ?? '-',
        ];
    }
}