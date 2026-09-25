<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cronograma de cuotas — Venta #{{ $venta->id }}</title>
    <style>
        @page { margin: 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.4; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 4px; }
        h2 { font-size: 10px; text-align: center; margin: 0 0 14px; color: #444; }
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.datos td { padding: 3px 4px; border-bottom: 1px solid #ddd; }
        table.datos td.label { width: 40%; color: #555; }
        table.cuotas { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.cuotas th, table.cuotas td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        table.cuotas th { background: #f2f2f2; }
        table.cuotas td.num, table.cuotas th.num { text-align: right; }
        table.cuotas tr.totales td { border-top: 2px solid #999; font-weight: bold; }
    </style>
</head>
<body>
    @php
        $emp = $venta->empresa;
        $c = $venta->cliente;
        $tipoCuotaLabels = ['diario' => 'Diario', 'semanal' => 'Semanal', 'quincenal' => 'Quincenal', 'mensual' => 'Mensual'];
    @endphp

    <h1>{{ $emp->razon_social ?: $emp->nombre }}</h1>
    <h2>Cronograma de cuotas — Venta N.&deg; {{ str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT) }}</h2>

    <table class="datos">
        <tr><td class="label">Cliente</td><td>{{ $c->nombre }} {{ $c->apellido }} &mdash; {{ mb_strtoupper($c->tipo_documento) }} {{ $c->numero_documento }}</td></tr>
        <tr><td class="label">Art&iacute;culo</td><td>{{ $venta->articulo->nombre }} @if($venta->articulo->marca) ({{ $venta->articulo->marca }} {{ $venta->articulo->modelo }}) @endif</td></tr>
        <tr><td class="label">Precio de venta</td><td>S/ {{ number_format((float) $venta->precio_venta, 2) }}</td></tr>
        <tr><td class="label">Inicial</td><td>S/ {{ number_format((float) $venta->inicial, 2) }}</td></tr>
        <tr><td class="label">Inter&eacute;s mensual</td><td>{{ number_format((float) $venta->interes, 2) }}%</td></tr>
        <tr><td class="label">Tipo de cuota</td><td>{{ $tipoCuotaLabels[$venta->tipo_cuota] ?? $venta->tipo_cuota }}</td></tr>
        <tr><td class="label">Saldo pendiente</td><td>S/ {{ number_format((float) $venta->saldo_pendiente, 2) }}</td></tr>
    </table>

    <table class="cuotas">
        <thead>
            <tr>
                <th>N.&deg;</th>
                <th>Vencimiento</th>
                <th class="num">Capital</th>
                <th class="num">Inter&eacute;s</th>
                <th class="num">Cuota</th>
                <th class="num">Abonado</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($venta->cuotas as $cuota)
                <tr>
                    <td>{{ $cuota->numero_cuota }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($cuota->fecha_vencimiento)->format('d/m/Y') }}</td>
                    <td class="num">S/ {{ number_format((float) $cuota->monto_capital, 2) }}</td>
                    <td class="num">S/ {{ number_format((float) $cuota->monto_interes, 2) }}</td>
                    <td class="num">S/ {{ number_format((float) $cuota->monto_total, 2) }}</td>
                    <td class="num">S/ {{ number_format((float) $cuota->monto_abonado, 2) }}</td>
                    <td>{{ $cuota->estado === 'pagada' ? 'Pagada' : 'Pendiente' }}</td>
                </tr>
            @endforeach
            <tr class="totales">
                <td colspan="2">Total</td>
                <td class="num">S/ {{ number_format((float) $venta->cuotas->sum('monto_capital'), 2) }}</td>
                <td class="num">S/ {{ number_format((float) $venta->cuotas->sum('monto_interes'), 2) }}</td>
                <td class="num">S/ {{ number_format((float) $venta->cuotas->sum('monto_total'), 2) }}</td>
                <td class="num">S/ {{ number_format((float) $venta->cuotas->sum('monto_abonado'), 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
