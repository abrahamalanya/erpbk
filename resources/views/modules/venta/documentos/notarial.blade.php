<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Documento Notarial #{{ $venta->id }}</title>
    <style>
        @page { margin: 2.5cm 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.35; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 4px; }
        .aviso { text-align: center; font-size: 9px; color: #b45309; margin-bottom: 14px; }
        h2 { font-size: 10px; margin-top: 10px; margin-bottom: 3px; }
        p { text-align: justify; margin: 4px 0; }
        ul.datos { margin: 4px 0; padding-left: 18px; }
        ul.datos li { margin: 1px 0; }
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
        $inmueble = $articulo;
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
    @endphp

    <h1>MINUTA DE TRANSFERENCIA DE INMUEBLE N.&deg; {{ str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT) }}</h1>
    <p class="aviso">
        MODELO GENERADO POR EL SISTEMA &mdash; debe ser revisado por un notario/abogado antes de elevarse a escritura
        p&uacute;blica. No reemplaza el documento notarial definitivo.
    </p>

    <p>
        Se&ntilde;or Notario: s&iacute;rvase extender en su Registro de Escrituras P&uacute;blicas una en la que conste el
        contrato de compraventa que celebran de una parte <strong>{{ mb_strtoupper($empresaNombre) }}</strong>
        @if($emp->ruc) , con RUC N.&deg; {{ $emp->ruc }}, @endif a quien en adelante se le denominar&aacute;
        <strong>EL VENDEDOR</strong>; y de la otra parte {{ mb_strtoupper($c->nombre.' '.$c->apellido) }}, identificado(a) con
        {{ mb_strtoupper($c->tipo_documento) }} N.&deg; {{ $c->numero_documento }}, a quien en adelante se le denominar&aacute;
        <strong>EL COMPRADOR</strong>; en los t&eacute;rminos siguientes:
    </p>

    <h2>PRIMERA: IDENTIFICACI&Oacute;N DEL INMUEBLE</h2>
    <ul class="datos">
        <li>Tipo: {{ $inmueble->tipo_inmueble ?? '-' }}</li>
        <li>Direcci&oacute;n: {{ $inmueble->direccion ?? '-' }}</li>
        <li>Distrito / Provincia / Departamento: {{ $inmueble->distrito ?? '-' }} / {{ $inmueble->provincia ?? '-' }} / {{ $inmueble->departamento ?? '-' }}</li>
        <li>&Aacute;rea de terreno: {{ $inmueble->area_terreno ?? '-' }} m&sup2;</li>
        <li>&Aacute;rea construida: {{ $inmueble->area_construida ?? '-' }} m&sup2;</li>
    </ul>

    <h2>SEGUNDA: OBJETO Y PRECIO</h2>
    <p>
        EL VENDEDOR transfiere la propiedad del inmueble descrito a favor de EL COMPRADOR por el precio de
        S/ {{ number_format((float) $venta->precio_venta, 2) }} ({{ NumeroALetras::soles($venta->precio_venta) }}).
    </p>

    <h2>TERCERA: FORMA DE PAGO</h2>
    <p>
        EL VENDEDOR declara haber recibido de EL COMPRADOR la totalidad del precio pactado
        @if($venta->forma_venta !== 'contado')
            , mediante un inicial de S/ {{ number_format((float) $venta->inicial, 2) }} y el saldo cancelado con
            posterioridad conforme a lo acordado entre las partes,
        @endif
        otorgando la m&aacute;s amplia y suficiente constancia de pago y cancelaci&oacute;n.
    </p>

    <h2>CUARTA: ENTREGA Y SANEAMIENTO</h2>
    <p>
        EL VENDEDOR hace entrega real y efectiva del inmueble a EL COMPRADOR y se obliga al saneamiento por evicci&oacute;n
        conforme a las disposiciones del C&oacute;digo Civil Peruano.
    </p>

    <p>Agregue Ud. Se&ntilde;or Notario las dem&aacute;s cl&aacute;usulas de estilo y curse los partes al Registro respectivo.</p>

    <p>{{ $fecha }}.</p>

    <table class="firmas">
        <tr>
            <td><div class="firma-espacio"></div><div class="firma-linea">{{ mb_strtoupper($empresaNombre) }}<br>(Vendedor)</div></td>
            <td><div class="firma-espacio"></div><div class="firma-linea">{{ mb_strtoupper($c->nombre.' '.$c->apellido) }}<br>(Comprador)</div></td>
        </tr>
    </table>
</body>
</html>
