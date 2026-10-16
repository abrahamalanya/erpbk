<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de cierre de caja</title>
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
    <h1>Reporte de cierre de caja</h1>
    <p>Usuario: {{ trim($usuario->nombre.' '.$usuario->apellido) }}</p>
    <p>Agencia: {{ $agencia?->nombre ?? 'Principal' }} | Fecha: {{ $cierre['fecha'] }} | Estado: {{ $cierre['estado'] }}</p>
    <p>Apertura: {{ $cierre['hora_apertura'] ?? '-' }} | Cierre: {{ $cierre['hora_cierre'] ?? '-' }} (America/Lima)</p>
    @if ($cierre['cierre_forzado'] || $cierre['cierre_automatico'])
        <p>Tipo de cierre: {{ $cierre['cierre_automatico'] ? 'Automatico' : 'Forzado' }}</p>
    @endif

    <h2>Billetajes</h2>
    <table>
        <thead><tr><th>Hora</th><th>Motivo</th><th>Medio</th><th class="money">Monto</th></tr></thead>
        <tbody>
            @forelse ($billetajes as $item)
                <tr><td>{{ $item['hora'] }}</td><td>{{ $item['motivo'] ?? $item['concepto'] }}</td><td>{{ $item['medio'] }}</td><td class="money">{{ $item['monto'] }}</td></tr>
            @empty
                <tr><td colspan="4">Sin billetajes</td></tr>
            @endforelse
        </tbody>
    </table>

    @foreach ($pagos_por_medio as $medio => $items)
        <h2>Cobros: {{ ucfirst($medio) }} - S/ {{ $totales_por_medio[$medio] }}</h2>
        <table>
            <thead><tr><th>Hora</th><th>Cliente</th><th>Credito</th><th>Operacion</th><th>Estado credito</th><th class="money">Monto</th></tr></thead>
            <tbody>
                @forelse ($items as $item)
                    <tr><td>{{ $item['hora'] }}</td><td>{{ $item['cliente']?->nombre }} {{ $item['cliente']?->apellido }}</td><td>{{ $item['credito_codigo'] }}</td><td>{{ $item['operacion'] }}</td><td>{{ $item['estado_credito'] ?? '-' }}{{ $item['dias_atraso'] ? ' ('.$item['dias_atraso'].'d)' : '' }}</td><td class="money">{{ $item['monto'] }}</td></tr>
                @empty
                    <tr><td colspan="6">Sin cobros</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach

    <h2>Ingresos adicionales</h2>
    <table>
        <thead><tr><th>Hora</th><th>Concepto</th><th>Observacion</th><th class="money">Monto</th></tr></thead>
        <tbody>
            @forelse ($ingresos as $item)
                <tr><td>{{ $item['hora'] }}</td><td>{{ $item['concepto'] }}</td><td>{{ $item['observacion'] }}</td><td class="money">{{ $item['monto'] }}</td></tr>
            @empty
                <tr><td colspan="4">Sin ingresos adicionales</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Desembolsos</h2>
    <table>
        <thead><tr><th>Hora</th><th>Cliente</th><th>Credito</th><th class="money">Monto</th></tr></thead>
        <tbody>
            @forelse ($desembolsos as $item)
                <tr><td>{{ $item['hora'] }}</td><td>{{ $item['cliente']?->nombre }} {{ $item['cliente']?->apellido }}</td><td>{{ $item['credito_codigo'] }}</td><td class="money">{{ $item['monto'] }}</td></tr>
            @empty
                <tr><td colspan="4">Sin desembolsos</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Gastos (egresos manuales)</h2>
    @forelse ($gastos_por_concepto as $grupo)
        <h2>{{ $grupo['concepto'] }} - S/ {{ $grupo['total'] }}</h2>
        <table>
            <thead><tr><th>Hora</th><th>Comprobante</th><th>Observacion</th><th class="money">Monto</th></tr></thead>
            <tbody>
                @foreach ($grupo['items'] as $item)
                    <tr><td>{{ $item['hora'] }}</td><td>{{ $item['serie'] ?? '-' }}-{{ $item['numero'] ?? '-' }}</td><td>{{ $item['observacion'] }}</td><td class="money">{{ $item['monto'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p>Sin gastos</p>
    @endforelse

    <h2>Resumen (S/)</h2>
    <table class="summary">
        @foreach ([
            'saldo_apertura' => 'Saldo de apertura',
            'total_billetaje' => '+ Billetaje',
            'total_cobrado' => '+ Cobrado',
            'total_ingresos' => '+ Ingresos adicionales',
            'total_desembolsos' => '- Desembolsos',
            'total_gastos' => '- Gastos',
            'saldo_total' => '= Saldo total',
            'saldo_efectivo' => 'Efectivo esperado',
            'monto_contado' => 'Efectivo contado',
            'faltante' => 'Faltante de efectivo',
            'sobrante' => 'Sobrante de efectivo',
            'neto' => 'Neto (saldo total + sobrante - faltante)',
        ] as $campo => $etiqueta)
            <tr><td>{{ $etiqueta }}</td><td class="money">{{ $resumen[$campo] ?? 'Pendiente' }}</td></tr>
        @endforeach
    </table>
</body>
</html>
