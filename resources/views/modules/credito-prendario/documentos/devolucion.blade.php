<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acta de Devolución de Bienes #{{ $credito->id }}</title>
    <style>
        @page { margin: 11mm 13mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.3px; color: #1a1a1a; line-height: 1.28; }
        h1 { font-size: 13px; text-align: center; margin: 0; }
        h2 { font-size: 9.8px; margin-top: 7px; margin-bottom: 2px; }
        p { text-align: justify; margin: 4px 0; }
        .numero { display: inline-block; border: 1px solid #000; padding: 3px 8px; font-weight: bold; font-size: 10px; }
        .logo { max-height: 42px; max-width: 100px; }
        table.bienes { width: 100%; border-collapse: collapse; margin: 5px 0; }
        table.bienes th, table.bienes td { border: 1px solid #999; padding: 2px 4px; font-size: 8.6px; }
        table.bienes th { background: #eee; }
        table.firmas { width: 100%; margin-top: 16px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: bottom; }
        .firma-imagen { max-height: 50px; max-width: 160px; }
        .firma-linea { margin-top: 6px; border-top: 1px solid #000; padding-top: 3px; }
        .firma-espacio { height: 40px; }
    </style>
</head>
<body>
    @php
        $fechaCancelacion = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $fechaDesembolso = $credito->fecha_desembolso?->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $ciudad = \Illuminate\Support\Str::after($credito->agencia->nombre, 'Agencia ');
        $empresaNombre = $credito->empresa->razon_social ?: $credito->empresa->nombre;
        $clienteNombre = strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido);
        $tipoDoc = strtoupper($credito->cliente->tipo_documento);

        $datosEmpresa = strtoupper($empresaNombre);
        if ($credito->empresa->ruc) {
            $datosEmpresa .= ', con RUC '.$credito->empresa->ruc;
        }
        if ($credito->empresa->domicilio_legal) {
            $datosEmpresa .= ', con domicilio en '.strtoupper($credito->empresa->domicilio_legal);
        }
        $datosEmpresa .= ', representado';
        if ($credito->empresa->representante_legal) {
            $datosEmpresa .= ' para efectos de este instrumento por '.strtoupper($credito->empresa->representante_legal);
        }
    @endphp

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="border: none; width: 100px; vertical-align: middle;">
                @if ($credito->empresa->logo_path)
                    <img class="logo" src="{{ $fotoDataUri($credito->empresa->logo_path, 300) }}">
                @endif
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;">
                <h1>ACTA DE DEVOLUCI&Oacute;N DE BIENES EN GARANT&Iacute;A CR&Eacute;DITO PRENDARIO</h1>
            </td>
            <td style="border: none; width: 90px; text-align: right; vertical-align: middle;">
                <span class="numero">N&deg; {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}</span>
            </td>
        </tr>
    </table>

    <p>
        Conste por el presente documento, el acta de entrega y recepci&oacute;n, que describe de una parte
        <strong>{{ $datosEmpresa }}</strong>, en adelante {{ strtoupper($empresaNombre) }}, y de la otra parte el
        se&ntilde;or(a) <strong>{{ $clienteNombre }}</strong>, con {{ $tipoDoc }} N&deg;
        {{ $credito->cliente->numero_documento }}, en adelante EL CLIENTE, en los t&eacute;rminos siguientes:
    </p>

    <h2>PRIMERO</h2>
    <p>
        EL CLIENTE suscribi&oacute; con {{ strtoupper($empresaNombre) }} un contrato de Pr&eacute;stamo por
        S/ {{ number_format($credito->monto_prestamo, 2) }} de fecha {{ $fechaDesembolso }}, constituyendo
        Garant&iacute;a Mobiliaria sobre el(los) bien(es) de su propiedad que se describe(n) a continuaci&oacute;n:
    </p>

    <table class="bienes">
        <tr>
            <th>N&deg;</th><th>Art&iacute;culo</th><th>Marca</th><th>Modelo</th><th>Serie</th><th>Valorizaci&oacute;n</th>
        </tr>
        @foreach ($credito->bienes as $i => $bien)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ strtoupper($bien->nombre) }}</td>
            <td>{{ $bien->marca ?? '-' }}</td>
            <td>{{ $bien->modelo ?? '-' }}</td>
            <td>{{ $bien->serie ?? '-' }}</td>
            <td>S/ {{ number_format($bien->valorizacion, 2) }}</td>
        </tr>
        @endforeach
    </table>

    <h2>SEGUNDO</h2>
    <p>
        Considerando, que el pr&eacute;stamo mencionado l&iacute;neas arriba ha sido cancelado en su integridad el
        d&iacute;a: {{ $fechaCancelacion }}, {{ strtoupper($empresaNombre) }} procede a entregar a EL CLIENTE el
        (los) art&iacute;culo(s) descrito(s) precedentemente, manifestando este &uacute;ltimo su conformidad.
    </p>

    <h2>TERCERO</h2>
    <p>
        La suscripci&oacute;n del presente documento constituye un reconocimiento expreso, de liberar a
        {{ strtoupper($empresaNombre) }} de cualquier responsabilidad de orden legal, que se produzca por reclamos
        que pudieran plantearse — presente y/o futuros — en relaci&oacute;n al art&iacute;culo, por: i) Estado de
        conservaci&oacute;n f&iacute;sica, ii) Funcionamiento, iii) Accesorios, iv) Cualquier otra situaci&oacute;n
        particular relacionada a la custodia del art&iacute;culo. Ambas partes suscriben la presente Acta en se&ntilde;al
        de conformidad y aceptaci&oacute;n de todos y cada uno de los t&eacute;rminos expuestos en la misma.
    </p>

    <p style="text-align: right;">{{ $ciudad }}, {{ $fechaCancelacion }}.</p>

    <table class="firmas">
        <tr>
            <td>
                @if ($credito->empresa->firma_path)
                    <img class="firma-imagen" src="{{ $fotoDataUri($credito->empresa->firma_path, 400) }}">
                @else
                    <div class="firma-espacio"></div>
                @endif
                <div class="firma-linea">
                    {{ strtoupper($empresaNombre) }}<br>
                    @if ($credito->empresa->ruc) RUC: {{ $credito->empresa->ruc }} @endif
                </div>
            </td>
            <td>
                <div class="firma-espacio"></div>
                <div class="firma-linea">
                    {{ $clienteNombre }}<br>
                    {{ $tipoDoc }} N&deg;: {{ $credito->cliente->numero_documento }}
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
