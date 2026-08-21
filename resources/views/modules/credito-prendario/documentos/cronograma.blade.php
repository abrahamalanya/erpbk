<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cronograma de cuotas — Crédito #{{ $credito->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 4px; }
        h2 { font-size: 13px; margin-top: 18px; margin-bottom: 6px; border-bottom: 1px solid #999; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td, th { padding: 3px 4px; vertical-align: top; }
        td.label { width: 35%; font-weight: bold; }
        .cuotas th { text-align: left; border-bottom: 1px solid #999; font-weight: bold; }
        .cuotas td { border-bottom: 1px solid #ddd; }
        .cuotas .totales td { border-top: 2px solid #999; border-bottom: none; font-weight: bold; }
        .num { text-align: right; }
    </style>
</head>
<body>
    <h1>CRONOGRAMA DE CUOTAS</h1>
    <p style="text-align: center;">{{ $credito->empresa->nombre }} — {{ $credito->agencia->nombre }}</p>

    <h2>Datos del crédito</h2>
    <table>
        <tr><td class="label">Cliente</td><td>{{ $credito->cliente->nombre }} {{ $credito->cliente->apellido }}</td></tr>
        <tr><td class="label">Monto del préstamo</td><td>{{ number_format($credito->monto_prestamo, 2) }}</td></tr>
        <tr><td class="label">Interés</td><td>{{ number_format($credito->interes, 2) }}%</td></tr>
        <tr><td class="label">Tipo de cuota</td><td>{{ ucfirst($credito->tipo_cuota) }}</td></tr>
        <tr><td class="label">Plazo</td><td>{{ $credito->plazo_dias }} días</td></tr>
        <tr><td class="label">Cantidad de cuotas</td><td>{{ $credito->cuotas->count() }}</td></tr>
        <tr><td class="label">Fecha de desembolso</td><td>{{ optional($credito->fecha_desembolso)->format('d/m/Y') }}</td></tr>
        <tr><td class="label">Fecha de vencimiento</td><td>{{ optional($credito->fecha_vencimiento)->format('d/m/Y') }}</td></tr>
    </table>

    <h2>Cuotas</h2>
    <table class="cuotas">
        <tr>
            <th>N.º</th>
            <th>Vencimiento</th>
            <th class="num">Capital</th>
            <th class="num">Interés</th>
            <th class="num">Cuota</th>
        </tr>
        @foreach ($credito->cuotas->sortBy('numero_cuota') as $cuota)
        <tr>
            <td>{{ $cuota->numero_cuota }}</td>
            <td>{{ optional($cuota->fecha_vencimiento)->format('d/m/Y') }}</td>
            <td class="num">{{ number_format($cuota->monto_capital, 2) }}</td>
            <td class="num">{{ number_format($cuota->monto_interes, 2) }}</td>
            <td class="num">{{ number_format($cuota->monto_total, 2) }}</td>
        </tr>
        @endforeach
        <tr class="totales">
            <td colspan="2">Total</td>
            <td class="num">{{ number_format($credito->cuotas->sum('monto_capital'), 2) }}</td>
            <td class="num">{{ number_format($credito->cuotas->sum('monto_interes'), 2) }}</td>
            <td class="num">{{ number_format($credito->cuotas->sum('monto_total'), 2) }}</td>
        </tr>
    </table>
</body>
</html>
