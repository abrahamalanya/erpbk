<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 1.2cm; size: landscape; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1a1a1a; }
        h1 { font-size: 15px; text-align: center; margin-bottom: 4px; }
        .generado { text-align: center; color: #666; font-size: 9px; margin-bottom: 10px; }

        /* La bolita es un SVG inline, no un span redondeado: dompdf no
           implementa border-radius: 50% y lo pintaría cuadrado. */
        .bolita { width: 9px; height: 9px; }

        .leyenda { margin-bottom: 12px; }
        .leyenda-fila { margin-bottom: 3px; }
        .leyenda-titulo { display: inline-block; width: 150px; font-size: 8px; font-weight: bold; color: #444; }
        .leyenda-tramo { display: inline-block; width: 92px; font-size: 7px; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 4px; text-align: left; }
        thead th { border-bottom: 2px solid #333; font-weight: bold; }
        tbody tr:nth-child(even) { background: #f5f5f5; }
        tbody td { border-bottom: 1px solid #ddd; }
        td.num { text-align: right; }
        .celda-bolita { white-space: nowrap; }
    </style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <p class="generado">Generado el {{ now()->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y, H:i') }}</p>

    <div class="leyenda">
        @foreach ($leyenda as $bloque)
            <div class="leyenda-fila">
                <span class="leyenda-titulo">{{ $bloque['titulo'] }}</span>
                @foreach ($bloque['tramos'] as $tramo)
                    <span class="leyenda-tramo">
                        <img class="bolita" src="{{ $tramo['bolita']->dataUri() }}">{{ $tramo['rango'] }} {{ $tramo['texto'] }}
                    </span>
                @endforeach
            </div>
        @endforeach
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($encabezados as $encabezado)
                    <th>{{ $encabezado }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $indice => $fila)
                <tr>
                    @foreach ($fila as $columna => $valor)
                        @php($numerico = $columna >= 9)
                        <td @class(['num' => $numerico, 'celda-bolita' => isset($bolitas[$indice][$columna])])>
                            @if (isset($bolitas[$indice][$columna]))
                                <img class="bolita" src="{{ $bolitas[$indice][$columna]->dataUri() }}">
                            @endif{{ $valor }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($encabezados) }}" style="text-align:center; color:#888;">Sin datos</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
