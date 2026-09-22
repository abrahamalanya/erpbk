<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Voucher de Venta #{{ $venta->id }}</title>
    <style>
        @page { margin: 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.4; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 4px; }
        h2 { font-size: 10px; text-align: center; margin: 0 0 14px; color: #444; }
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.datos td { padding: 3px 4px; border-bottom: 1px solid #ddd; }
        table.datos td.label { width: 40%; color: #555; }
        table.pagos { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.pagos th, table.pagos td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        table.pagos th { background: #f2f2f2; }
        .total { text-align: right; font-size: 12px; font-weight: bold; margin-top: 8px; }
        .firma { margin-top: 60px; text-align: center; }
        .firma .linea { border-top: 1px solid #000; width: 260px; margin: 0 auto; padding-top: 4px; }
    </style>
</head>
<body>
    @php
        $emp = $venta->empresa;
        $c = $venta->cliente;
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
    @endphp

    <h1>{{ $emp->razon_social ?: $emp->nombre }}</h1>
    <h2>Voucher de Venta N.&deg; {{ str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT) }} &mdash; {{ $fecha }}</h2>

    <table class="datos">
        <tr><td class="label">Cliente</td><td>{{ $c->nombre }} {{ $c->apellido }} &mdash; {{ mb_strtoupper($c->tipo_documento) }} {{ $c->numero_documento }}</td></tr>
        <tr><td class="label">Art&iacute;culo</td><td>{{ $articulo->nombre }} @if($articulo->marca) ({{ $articulo->marca }} {{ $articulo->modelo }}) @endif</td></tr>
        <tr><td class="label">Modalidad</td><td>{{ ['contado' => 'Contado', 'credito' => 'Cr&eacute;dito', 'apartado' => 'Apartado'][$venta->forma_venta] }}</td></tr>
        <tr><td class="label">Precio de venta</td><td>S/ {{ number_format((float) $venta->precio_venta, 2) }}</td></tr>
        <tr><td class="label">Vendido por</td><td>{{ $venta->vendidoPor->name }}</td></tr>
    </table>

    <table class="pagos">
        <thead>
            <tr><th>Fecha</th><th>Tipo</th><th>Medio</th><th style="text-align: right;">Monto</th></tr>
        </thead>
        <tbody>
            @foreach ($venta->pagos->whereNull('anulado_at') as $pago)
                <tr>
                    <td>{{ $pago->created_at->format('d/m/Y') }}</td>
                    <td>{{ ucfirst($pago->tipo) }}</td>
                    <td>{{ ucfirst($pago->medio) }}</td>
                    <td style="text-align: right;">S/ {{ number_format((float) $pago->monto, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="total">Total pagado: S/ {{ number_format((float) $venta->pagos->whereNull('anulado_at')->sum('monto'), 2) }}</p>

    <div class="firma">
        <div class="linea">{{ mb_strtoupper($c->nombre.' '.$c->apellido) }}</div>
        <p>Cliente</p>
    </div>
</body>
</html>
