<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 1.2cm; size: portrait; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; }
        h1 { font-size: 15px; text-align: center; margin-bottom: 4px; }
        .generado { text-align: center; color: #666; font-size: 9px; margin-bottom: 14px; }

        .seccion { margin-bottom: 14px; }
        .seccion-titulo { font-size: 10px; font-weight: bold; margin-bottom: 4px; padding-bottom: 2px; border-bottom: 1px solid #333; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 4px; text-align: left; }
        thead th { border-bottom: 1px solid #999; font-weight: bold; font-size: 8px; color: #444; }
        tbody td { border-bottom: 1px solid #eee; }
        td.num, th.num { text-align: right; }
        tfoot td { border-top: 1px solid #333; font-weight: bold; padding-top: 4px; }
        .vacio { text-align: center; color: #888; font-style: italic; }

        .resultado { margin-top: 16px; border: 1px solid #333; }
        .resultado td { padding: 5px 8px; border-bottom: 1px solid #ddd; }
        .resultado tr:last-child td { border-bottom: none; }
        .resultado .etiqueta { font-weight: bold; width: 55%; }
        .resultado .valor { text-align: right; font-size: 11px; }
        .negativo { color: #b91c1c; }
    </style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <p class="generado">Generado el {{ now()->locale('es')->translatedFormat('d \d\e F \d\e\l Y, H:i') }}</p>

    @foreach ($secciones as $seccion)
        <div class="seccion">
            <div class="seccion-titulo">{{ $seccion['titulo'] }}</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 26%;">Cuenta</th>
                        <th style="width: 13%;">Fecha</th>
                        <th>Descripción</th>
                        <th class="num" style="width: 18%;">Monto</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($seccion['filas'] as $fila)
                        <tr>
                            <td>{{ $fila['cuenta'] }}</td>
                            <td>{{ $fila['fecha'] }}</td>
                            <td>{{ $fila['descripcion'] }}</td>
                            <td class="num">{{ $fila['monto'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="vacio">Sin movimientos</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3">Total</td>
                        <td class="num">{{ $seccion['total'] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endforeach

    <table class="resultado">
        <tr>
            <td class="etiqueta">Total ingresos (Ingresos + Cobranzas + Ventas)</td>
            <td class="valor">{{ number_format((float) $totales['total_ingresos'], 2) }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Total egresos (Egresos + Desembolsos)</td>
            <td class="valor">{{ number_format((float) $totales['total_egresos'], 2) }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Utilidad / Pérdida Neta</td>
            <td class="valor {{ (float) $utilidad_neta < 0 ? 'negativo' : '' }}">{{ $utilidad_neta }}</td>
        </tr>
        <tr>
            <td class="etiqueta">Margen de Utilidad Bruta</td>
            <td class="valor">{{ $margen_utilidad_bruta }}</td>
        </tr>
    </table>
</body>
</html>
