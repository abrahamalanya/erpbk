<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 1cm; size: landscape; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1a1a1a; }
        h1 { font-size: 15px; text-align: center; margin-bottom: 4px; }
        .generado { text-align: center; color: #666; font-size: 9px; margin-bottom: 10px; }
        .sub { text-align: center; color: #444; font-size: 9px; margin-bottom: 12px; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 3px; text-align: left; }
        thead th { border-bottom: 2px solid #333; font-weight: bold; font-size: 7.5px; color: #333; }
        tbody tr:nth-child(even) { background: #f5f5f5; }
        tbody td { border-bottom: 1px solid #ddd; }
        th.num, td.num { text-align: right; }
        tfoot td { border-top: 2px solid #333; font-weight: bold; padding-top: 4px; }
        .vacio { text-align: center; color: #888; font-style: italic; }
    </style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <p class="sub">
        Periodo {{ $periodo }} &nbsp;|&nbsp; {{ $agencia }} &nbsp;|&nbsp; Fecha de pago {{ $fecha_pago }}
    </p>
    <p class="generado">Generado el {{ now()->locale('es')->translatedFormat('d \d\e F \d\e\l Y, H:i') }}</p>

    <table>
        <thead>
            <tr>
                @foreach ($encabezados as $indice => $encabezado)
                    {{-- Las columnas de la 6ta en adelante son montos. --}}
                    <th @class(['num' => $indice >= 5])>{{ $encabezado }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    @foreach ($fila as $indice => $valor)
                        <td @class(['num' => $indice >= 5])>{{ $valor }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($encabezados) }}" class="vacio">Sin trabajadores</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">TOTALES</td>
                <td class="num">{{ number_format((float) $totales['ingresos'], 2) }}</td>
                <td class="num">{{ number_format((float) $totales['descuentos_devengado'], 2) }}</td>
                <td class="num">{{ number_format((float) $totales['descuentos_pagado'], 2) }}</td>
                <td class="num">{{ number_format((float) $totales['tributos_trabajador'], 2) }}</td>
                <td class="num">{{ number_format((float) $totales['neto'], 2) }}</td>
                <td class="num">{{ number_format((float) $totales['aportes_empleador'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
