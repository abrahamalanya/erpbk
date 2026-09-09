<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Voucher de pago — Crédito #{{ $credito->id }}</title>
    <style>
        @page { margin: 3cm 1cm 3cm 3cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 2px; }
        .sub { text-align: center; margin: 0 0 12px; color: #555; }
        h2 { font-size: 13px; margin-top: 16px; margin-bottom: 6px; border-bottom: 1px solid #999; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        td, th { padding: 3px 4px; vertical-align: top; }
        td.label { width: 42%; font-weight: bold; }
        .num { text-align: right; }
        .desglose th { text-align: left; border-bottom: 1px solid #999; }
        .desglose td { border-bottom: 1px solid #ddd; }
        .desglose .tot td { border-top: 2px solid #999; border-bottom: none; font-weight: bold; }
        .monto { font-size: 20px; font-weight: bold; text-align: center; margin: 10px 0; }
        .firmas { margin-top: 40px; }
        .firmas td { width: 50%; text-align: center; }
        .firma-linea { border-top: 1px solid #000; padding-top: 3px; }
    </style>
</head>
<body>
    @php
        $operacionLabel = [
            'refrendo' => 'REFRENDO',
            'adenda' => 'ADENDA',
            'liquidacion' => 'LIQUIDACIÓN',
        ][$datos['operacion'] ?? ''] ?? 'PAGO';
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y, H:i');
        $medioLabel = ucfirst(str_replace('_', ' ', $datos['medio'] ?? 'efectivo'));
    @endphp

    <h1>VOUCHER DE PAGO — {{ $operacionLabel }}</h1>
    <p class="sub">{{ $credito->empresa->razon_social ?: $credito->empresa->nombre }} — {{ $credito->agencia->nombre }}</p>

    <table>
        <tr><td class="label">Voucher N.º</td><td>{{ str_pad($documento->id, 8, '0', STR_PAD_LEFT) }}</td></tr>
        <tr><td class="label">Crédito</td><td>#{{ $datos['credito_id'] ?? $credito->id }} ({{ ucfirst($credito->tipo_credito) }})</td></tr>
        @if (! empty($datos['credito_sucesor_id']))
            <tr><td class="label">Crédito sucesor</td><td>#{{ $datos['credito_sucesor_id'] }}</td></tr>
        @endif
        <tr><td class="label">Fecha y hora</td><td>{{ $fecha }}</td></tr>
        <tr><td class="label">Cliente</td><td>{{ strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido) }} — {{ strtoupper($credito->cliente->tipo_documento) }} {{ $credito->cliente->numero_documento }}</td></tr>
        <tr><td class="label">Medio de pago</td><td>{{ $medioLabel }}</td></tr>
    </table>

    <div class="monto">Pagado: S/ {{ number_format((float) ($datos['monto_pagado'] ?? 0), 2) }}</div>

    <h2>Desglose</h2>
    <table class="desglose">
        <tr><th>Concepto</th><th class="num">Monto (S/)</th></tr>
        @if (($datos['operacion'] ?? '') === 'liquidacion')
            <tr><td>Capital</td><td class="num">{{ number_format((float) ($datos['capital'] ?? 0), 2) }}</td></tr>
            <tr><td>Interés</td><td class="num">{{ number_format((float) ($datos['interes'] ?? 0), 2) }}</td></tr>
            <tr><td>Mora ({{ $datos['dias_mora'] ?? 0 }} días)</td><td class="num">{{ number_format((float) ($datos['mora'] ?? 0), 2) }}</td></tr>
            <tr class="tot"><td>Total a pagar</td><td class="num">{{ number_format((float) ($datos['total'] ?? 0), 2) }}</td></tr>
        @else
            <tr><td>Interés cobrado</td><td class="num">{{ number_format((float) ($datos['interes'] ?? 0), 2) }}</td></tr>
            <tr><td>Abono a capital</td><td class="num">{{ number_format((float) ($datos['abono_capital'] ?? 0), 2) }}</td></tr>
            <tr class="tot"><td>Capital pendiente (crédito sucesor)</td><td class="num">{{ number_format((float) ($datos['saldo_capital'] ?? 0), 2) }}</td></tr>
        @endif
        <tr><td>Vuelto</td><td class="num">{{ number_format((float) ($datos['vuelto'] ?? 0), 2) }}</td></tr>
    </table>

    @if (($datos['operacion'] ?? '') === 'adenda' && (isset($datos['nuevo_interes']) || isset($datos['nuevo_tipo_cuota'])))
        <h2>Nuevas condiciones del sucesor</h2>
        <table>
            <tr><td class="label">Interés</td><td>{{ number_format((float) $datos['nuevo_interes'], 2) }}%</td></tr>
            <tr><td class="label">Tipo de cuota</td><td>{{ ucfirst($datos['nuevo_tipo_cuota']) }}</td></tr>
        </table>
    @endif

    <table class="firmas">
        <tr>
            <td><div class="firma-linea">Cobrado por (asesor)</div></td>
            <td><div class="firma-linea">Conforme (cliente)</div></td>
        </tr>
    </table>
</body>
</html>
