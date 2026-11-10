<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de cierre de bóveda</title>
    <style>
        @page { margin: 18px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        h2 { font-size: 11px; margin: 12px 0 4px; }
        p { margin: 3px 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border-bottom: 1px solid #ddd; padding: 4px; text-align: left; }
        th { background: #eee; }
        tr { page-break-inside: avoid; }
        .money { text-align: right; white-space: nowrap; }
        .summary { width: 65%; margin-top: 12px; }
    </style>
</head>
<body>
    <h1>Reporte de cierre de bóveda</h1>
    <p>Bóveda: {{ $boveda->tipo === 'principal' ? 'Principal' : ($boveda->agencia?->nombre ?? 'Agencia') }}</p>
    <p>Fecha: {{ $ciclo['fecha'] }} | Estado: {{ $ciclo['estado'] === 'abierta' ? 'Abierta' : 'Cerrada' }}</p>
    <p>
        Aperturada por: {{ $abierta_por ? trim($abierta_por->nombre.' '.$abierta_por->apellido) : '—' }} |
        Cerrada por: {{ $cerrada_por ? trim($cerrada_por->nombre.' '.$cerrada_por->apellido) : '—' }}
    </p>

    <h2>Ingresos</h2>
    <table>
        <thead><tr><th>Hora</th><th>Concepto</th><th>Origen</th><th>Registrado por</th><th class="money">Monto</th></tr></thead>
        <tbody>
            @forelse ($ingresos as $item)
                <tr>
                    <td>{{ $item['hora'] }}</td>
                    <td>{{ $item['concepto'] }}</td>
                    <td>{{ $item['origen'] ?? '-' }}</td>
                    <td>{{ $item['registrado_por'] ? trim($item['registrado_por']->nombre.' '.$item['registrado_por']->apellido) : '—' }}</td>
                    <td class="money">{{ $item['monto'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Sin ingresos</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Billetaje entregado a cajas</h2>
    <table>
        <thead><tr><th>Hora</th><th>Asesor</th><th>Registrado por</th><th class="money">Monto</th></tr></thead>
        <tbody>
            @forelse ($billetajes_entregados as $item)
                <tr>
                    <td>{{ $item['hora'] }}</td>
                    <td>{{ $item['asesor'] ? trim($item['asesor']->nombre.' '.$item['asesor']->apellido) : '—' }}</td>
                    <td>{{ $item['registrado_por'] ? trim($item['registrado_por']->nombre.' '.$item['registrado_por']->apellido) : '—' }}</td>
                    <td class="money">{{ $item['monto'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Sin billetaje entregado</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Egresos (retiros / devoluciones / traspasos)</h2>
    <table>
        <thead><tr><th>Hora</th><th>Concepto</th><th>Origen</th><th>Registrado por</th><th class="money">Monto</th></tr></thead>
        <tbody>
            @forelse ($egresos as $item)
                <tr>
                    <td>{{ $item['hora'] }}</td>
                    <td>{{ $item['concepto'] }}</td>
                    <td>{{ $item['origen'] ?? '-' }}</td>
                    <td>{{ $item['registrado_por'] ? trim($item['registrado_por']->nombre.' '.$item['registrado_por']->apellido) : '—' }}</td>
                    <td class="money">{{ $item['monto'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Sin egresos</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Resumen (S/)</h2>
    <table class="summary">
        @foreach ([
            'saldo_apertura' => 'Saldo de apertura',
            'total_ingresos' => '= Total ingresos',
            'total_billetaje_entregado' => 'Billetaje entregado (incluido en egresos)',
            'total_egresos' => '= Total egresos',
            'faltante' => 'Faltante de efectivo',
            'sobrante' => 'Sobrante de efectivo',
            'saldo_arqueo_cierre' => 'Efectivo contado al cierre',
            'saldo_cierre' => '= Saldo de cierre',
        ] as $campo => $etiqueta)
            <tr><td>{{ $etiqueta }}</td><td class="money">{{ $resumen[$campo] ?? 'Pendiente' }}</td></tr>
        @endforeach
    </table>
</body>
</html>
