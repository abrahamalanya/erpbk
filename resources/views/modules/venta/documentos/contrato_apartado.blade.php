<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de Apartado #{{ $venta->id }}</title>
    <style>
        @page { margin: 2.5cm 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.35; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 14px; }
        h2 { font-size: 10px; margin-top: 10px; margin-bottom: 3px; }
        p { text-align: justify; margin: 4px 0; }
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
        $fechaLimite = $venta->fecha_limite->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
    @endphp

    <h1>CONTRATO DE APARTADO N.&deg; {{ str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT) }}</h1>

    <p>
        Conste por el presente documento el contrato de apartado que celebran de una parte
        <strong>{{ mb_strtoupper($empresaNombre) }}</strong>, a quien en adelante se le denominar&aacute;
        <strong>EL VENDEDOR</strong>; y de la otra parte {{ mb_strtoupper($c->nombre.' '.$c->apellido) }}, identificado(a) con
        {{ mb_strtoupper($c->tipo_documento) }} N.&deg; {{ $c->numero_documento }}, a quien en adelante se le denominar&aacute;
        <strong>EL COMPRADOR</strong>; en los t&eacute;rminos siguientes:
    </p>

    <h2>PRIMERA: OBJETO Y PRECIO</h2>
    <p>
        EL VENDEDOR aparta a favor de EL COMPRADOR el bien <strong>{{ $articulo->nombre }}</strong>
        @if($articulo->marca) ({{ $articulo->marca }} {{ $articulo->modelo }}) @endif, por el precio total de
        S/ {{ number_format((float) $venta->precio_venta, 2) }} ({{ NumeroALetras::soles($venta->precio_venta) }}).
    </p>

    <h2>SEGUNDA: INICIAL Y PLAZO</h2>
    <p>
        EL COMPRADOR entrega en este acto un inicial de S/ {{ number_format((float) $venta->inicial, 2) }}
        ({{ NumeroALetras::soles($venta->inicial) }}) y se obliga a cancelar el saldo de
        S/ {{ number_format((float) $venta->saldo_pendiente, 2) }} mediante uno o m&aacute;s abonos, hasta el
        <strong>{{ $fechaLimite }}</strong>, fecha l&iacute;mite de cancelaci&oacute;n.
    </p>

    <h2>TERCERA: CONSECUENCIA DEL INCUMPLIMIENTO</h2>
    <p>
        Si al {{ $fechaLimite }} EL COMPRADOR no ha cancelado la totalidad del precio pactado, el presente contrato queda
        resuelto de pleno derecho, EL COMPRADOR pierde el inicial y los abonos entregados a favor de EL VENDEDOR, y el bien
        vuelve a estar disponible para la venta.
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
