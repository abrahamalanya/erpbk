<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 1.5cm; size: landscape; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 15px; text-align: center; margin-bottom: 4px; }
        .generado { text-align: center; color: #666; font-size: 9px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; text-align: left; }
        thead th { border-bottom: 2px solid #333; font-weight: bold; }
        tbody tr:nth-child(even) { background: #f5f5f5; }
        tbody td { border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <p class="generado">Generado el {{ now()->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y, H:i') }}</p>

    <table>
        <thead>
            <tr>
                @foreach ($encabezados as $encabezado)
                    <th>{{ $encabezado }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    @foreach ($fila as $valor)
                        <td>{{ $valor }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($encabezados) }}" style="text-align:center; color:#888;">Sin datos</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
