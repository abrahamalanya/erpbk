<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Voucher de desembolso — Crédito #{{ $credito->id }}</title>
    <style>
        @page { margin: 3cm 1cm 3cm 3cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 2px; }
        .sub { text-align: center; margin: 0 0 12px; color: #555; }
        h2 { font-size: 13px; margin-top: 16px; margin-bottom: 6px; border-bottom: 1px solid #999; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        td { padding: 3px 4px; vertical-align: top; }
        td.label { width: 38%; font-weight: bold; }
        .monto { font-size: 20px; font-weight: bold; text-align: center; margin: 10px 0; }
        .firmas { margin-top: 44px; }
        .firmas td { width: 50%; text-align: center; }
        .firma-linea { border-top: 1px solid #000; padding-top: 3px; }
    </style>
</head>
<body>
    @php
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y, H:i');
        $sinCaja = ($datos['medio'] ?? null) === 'sin_movimiento_caja';
    @endphp

    <h1>VOUCHER DE DESEMBOLSO</h1>
    <p class="sub">{{ $credito->empresa->razon_social ?: $credito->empresa->nombre }} — {{ $credito->agencia->nombre }}</p>

    <table>
        <tr><td class="label">Voucher N.º</td><td>{{ str_pad($documento->id, 8, '0', STR_PAD_LEFT) }}</td></tr>
        <tr><td class="label">Crédito</td><td>#{{ $credito->id }} ({{ ucfirst($credito->tipo_credito) }})</td></tr>
        <tr><td class="label">Fecha y hora</td><td>{{ $fecha }}</td></tr>
        <tr><td class="label">Cliente</td><td>{{ strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido) }} — {{ strtoupper($credito->cliente->tipo_documento) }} {{ $credito->cliente->numero_documento }}</td></tr>
    </table>

    <div class="monto">S/ {{ number_format((float) ($datos['monto'] ?? $credito->monto_prestamo), 2) }}</div>

    <h2>Condiciones</h2>
    <table>
        <tr><td class="label">Interés</td><td>{{ number_format((float) ($datos['interes'] ?? $credito->interes), 2) }}%</td></tr>
        <tr><td class="label">Tipo de cuota</td><td>{{ ucfirst($datos['tipo_cuota'] ?? $credito->tipo_cuota) }}</td></tr>
        <tr><td class="label">N.º de cuotas</td><td>{{ $datos['numero_cuotas'] ?? $credito->cuotas->count() }}</td></tr>
        <tr><td class="label">Plazo</td><td>{{ $datos['plazo_dias'] ?? $credito->plazo_dias }} días</td></tr>
        <tr><td class="label">Fecha de desembolso</td><td>{{ \Illuminate\Support\Carbon::parse($datos['fecha_desembolso'] ?? $credito->fecha_desembolso)->format('d/m/Y') }}</td></tr>
        <tr><td class="label">Fecha de vencimiento</td><td>{{ \Illuminate\Support\Carbon::parse($datos['fecha_vencimiento'] ?? $credito->fecha_vencimiento)->format('d/m/Y') }}</td></tr>
        <tr><td class="label">Entrega</td><td>{{ $sinCaja ? 'Sin movimiento de caja (adenda / reestructuración)' : 'Efectivo — caja del asesor' }}</td></tr>
        @if (! $sinCaja && isset($datos['saldo_caja']))
            <tr><td class="label">Saldo de caja tras el desembolso</td><td>S/ {{ number_format((float) $datos['saldo_caja'], 2) }}</td></tr>
        @endif
    </table>

    <h2>Garantías</h2>
    <table>
        @foreach ($garantias as $g)
            <tr><td class="label">{{ $g->codigo }}</td><td>{{ strtoupper($g->nombre) }}</td></tr>
        @endforeach
    </table>

    <table class="firmas">
        <tr>
            <td><div class="firma-linea">Entregado por (asesor)</div></td>
            <td><div class="firma-linea">Recibí conforme (cliente)</div></td>
        </tr>
    </table>
</body>
</html>
