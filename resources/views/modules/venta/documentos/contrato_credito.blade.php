<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de Venta a Cr&eacute;dito #{{ $venta->id }}</title>
    <style>
        @page { margin: 2.5cm 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.35; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 14px; }
        h2 { font-size: 10px; margin-top: 10px; margin-bottom: 3px; }
        p { text-align: justify; margin: 4px 0; }
        table.cronograma { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.cronograma th, table.cronograma td { border: 1px solid #ccc; padding: 3px 5px; font-size: 10px; }
        table.cronograma th { background: #f2f2f2; }
        table.firmas { width: 100%; margin-top: 45px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: bottom; }
        .firma-espacio { height: 60px; }
        .firma-linea { margin-top: 6px; border-top: 1px solid #000; padding-top: 3px; }
    </style>
</head>
<body>
    @php
        use App\Nucleo\Support\NumeroALetras;

        $emp = $venta->empresa;
        $empresaNombre = $emp->razon_social ?: $emp->nombre;
        $c = $venta->cliente;
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
    @endphp

    <h1>CONTRATO DE VENTA A CR&Eacute;DITO N.&deg; {{ str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT) }}</h1>

    <p>
        Conste por el presente documento el contrato de venta a cr&eacute;dito que celebran de una parte
        <strong>{{ mb_strtoupper($empresaNombre) }}</strong>, a quien en adelante se le denominar&aacute;
        <strong>EL VENDEDOR</strong>; y de la otra parte {{ mb_strtoupper($c->nombre.' '.$c->apellido) }}, identificado(a) con
        {{ mb_strtoupper($c->tipo_documento) }} N.&deg; {{ $c->numero_documento }}, a quien en adelante se le denominar&aacute;
        <strong>EL COMPRADOR</strong>; en los t&eacute;rminos siguientes:
    </p>

    <h2>PRIMERA: OBJETO</h2>
    <p>
        EL VENDEDOR transfiere a EL COMPRADOR el bien <strong>{{ $articulo->nombre }}</strong>
        @if($articulo->marca) ({{ $articulo->marca }} {{ $articulo->modelo }}) @endif, por el precio total de
        S/ {{ number_format((float) $venta->precio_venta, 2) }} ({{ NumeroALetras::soles($venta->precio_venta) }}).
    </p>

    <h2>SEGUNDA: FORMA DE PAGO</h2>
    <p>
        EL COMPRADOR entrega en este acto un inicial de S/ {{ number_format((float) $venta->inicial, 2) }}
        ({{ NumeroALetras::soles($venta->inicial) }}) y se obliga a cancelar el saldo en
        {{ $venta->numero_cuotas }} cuota(s), conforme al siguiente cronograma
        @if((float) $venta->interes > 0) (con una tasa de inter&eacute;s de {{ $venta->interes }}% mensual) @else (sin inter&eacute;s) @endif:
    </p>

    <table class="cronograma">
        <thead>
            <tr><th>N.&deg;</th><th>Vencimiento</th><th>Capital</th><th>Inter&eacute;s</th><th>Total</th></tr>
        </thead>
        <tbody>
            @foreach ($venta->cuotas as $cuota)
                <tr>
                    <td>{{ $cuota->numero_cuota }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($cuota->fecha_vencimiento)->format('d/m/Y') }}</td>
                    <td>S/ {{ number_format((float) $cuota->monto_capital, 2) }}</td>
                    <td>S/ {{ number_format((float) $cuota->monto_interes, 2) }}</td>
                    <td>S/ {{ number_format((float) $cuota->monto_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>TERCERA: RESERVA DE PROPIEDAD</h2>
    <p>
        La propiedad del bien queda reservada a favor de EL VENDEDOR hasta la cancelaci&oacute;n total del precio pactado. El
        incumplimiento en el pago de las cuotas facultar&aacute; a EL VENDEDOR a resolver el presente contrato conforme a ley.
    </p>

    <p>En se&ntilde;al de conformidad, las partes firman el presente documento con fecha {{ $fecha }}.</p>

    <table class="firmas">
        <tr>
            <td><div class="firma-espacio"></div><div class="firma-linea">{{ mb_strtoupper($empresaNombre) }}<br>(Vendedor)</div></td>
            <td><div class="firma-espacio"></div><div class="firma-linea">{{ mb_strtoupper($c->nombre.' '.$c->apellido) }}<br>(Comprador)</div></td>
        </tr>
    </table>
</body>
</html>
