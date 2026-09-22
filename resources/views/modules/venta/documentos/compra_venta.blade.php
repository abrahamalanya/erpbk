<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de Compra y Venta #{{ $venta->id }}</title>
    <style>
        @page { margin: 2.5cm 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.35; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 14px; }
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
    <!--
        MODELO base para venta al contado/crédito/apartado de un vehículo de
        tienda, adaptado de credito-vehicular/documentos/contrato_transferencia.
        Validar el texto legal con asesoría antes de usarlo en producción.
    -->
    @php
        use App\Nucleo\Support\NumeroALetras;

        $emp = $venta->empresa;
        $empresaNombre = $emp->razon_social ?: $emp->nombre;
        $c = $venta->cliente;
        $vehiculo = $articulo;
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');

        $datosTransferente = mb_strtoupper($empresaNombre);
        if ($emp->ruc) {
            $datosTransferente .= ', identificada con RUC N.º '.$emp->ruc;
        }
        if ($emp->domicilio_legal) {
            $datosTransferente .= ', con domicilio en '.mb_strtoupper($emp->domicilio_legal);
        }
    @endphp

    <h1>CONTRATO DE COMPRA Y VENTA DE VEH&Iacute;CULO N.&deg; {{ str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT) }}</h1>

    <p>
        Conste por el presente documento privado de compra y venta vehicular que celebran de una parte,
        <strong>{{ $datosTransferente }}</strong>, a quien en adelante se le denominar&aacute; <strong>EL VENDEDOR</strong>; y de
        la otra parte, {{ mb_strtoupper($c->nombre.' '.$c->apellido) }}, identificado(a) con
        {{ mb_strtoupper($c->tipo_documento) }} N.&deg; {{ $c->numero_documento }}, a quien en adelante se le denominar&aacute;
        <strong>EL COMPRADOR</strong>; en los t&eacute;rminos y condiciones siguientes:
    </p>

    <h2>PRIMERA: IDENTIFICACI&Oacute;N DEL VEH&Iacute;CULO</h2>
    <p>El veh&iacute;culo materia de venta tiene las siguientes caracter&iacute;sticas:</p>
    <ul class="datos">
        <li>Marca: {{ $vehiculo->marca ?? '-' }}</li>
        <li>Modelo: {{ $vehiculo->modelo ?? '-' }}</li>
        <li>Placa de Rodaje: {{ strtoupper($vehiculo->placa ?? '-') }}</li>
        <li>N&uacute;mero de Serie: {{ $vehiculo->serie ?? '-' }}</li>
        <li>Color: {{ $vehiculo->color ?? '-' }}</li>
    </ul>

    <h2>SEGUNDA: OBJETO Y PRECIO</h2>
    <p>
        EL VENDEDOR transfiere en forma definitiva, irrevocable y a t&iacute;tulo oneroso a favor de EL COMPRADOR la
        propiedad del veh&iacute;culo descrito, por el precio de S/ {{ number_format((float) $venta->precio_venta, 2) }}
        ({{ NumeroALetras::soles($venta->precio_venta) }}).
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

    <h2>CUARTA: ENTREGA</h2>
    <p>
        Con la firma del presente documento, EL VENDEDOR hace entrega real y efectiva del veh&iacute;culo a EL COMPRADOR,
        qui&eacute;n lo recibe a su entera satisfacci&oacute;n. Desde esta fecha, todos los riesgos y responsabilidades
        derivadas de la posesi&oacute;n y uso del veh&iacute;culo son asumidos exclusivamente por EL COMPRADOR.
    </p>

    <h2>QUINTA: DECLARACI&Oacute;N DEL COMPRADOR</h2>
    <p>
        EL COMPRADOR declara haber inspeccionado previamente el veh&iacute;culo, conocer su estado de conservaci&oacute;n y
        condiciones, acept&aacute;ndolo en las condiciones en que se encuentra, sin reserva alguna.
    </p>

    <p>
        En se&ntilde;al de conformidad, las partes firman el presente documento por duplicado, con fecha {{ $fecha }}.
    </p>

    <table class="firmas">
        <tr>
            <td><div class="firma-espacio"></div><div class="firma-linea">{{ mb_strtoupper($empresaNombre) }}<br>(Vendedor)</div></td>
            <td><div class="firma-espacio"></div><div class="firma-linea">{{ mb_strtoupper($c->nombre.' '.$c->apellido) }}<br>(Comprador)</div></td>
        </tr>
    </table>
</body>
</html>
