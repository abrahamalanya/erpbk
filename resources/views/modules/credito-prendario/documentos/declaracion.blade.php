<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Declaración Jurada #{{ $credito->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 24px; }
        p { text-align: justify; margin: 6px 0; }
        table.bienes { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table.bienes th, table.bienes td { border: 1px solid #999; padding: 3px 5px; font-size: 10px; }
        table.bienes th { background: #eee; }
        .firma-linea { margin-top: 80px; border-top: 1px solid #000; width: 260px; text-align: center; padding-top: 4px; margin-left: auto; margin-right: auto; }
    </style>
</head>
<body>
    @php
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $ciudad = \Illuminate\Support\Str::after($credito->agencia->nombre, 'Agencia ');
        $clienteNombre = strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido);
        $tipoDoc = strtoupper($credito->cliente->tipo_documento);
    @endphp

    <h1>DECLARACI&Oacute;N JURADA</h1>

    <p>
        Yo, {{ $clienteNombre }} identificado(a) con {{ $tipoDoc }} N&deg; {{ $credito->cliente->numero_documento }}@if ($credito->cliente->direccion)
        domiciliado(a) en {{ strtoupper($credito->cliente->direccion) }}@endif.
    </p>
    <p><strong>DECLARO BAJO JURAMENTO:</strong></p>
    <p>
        Ser el &uacute;nico due&ntilde;o y leg&iacute;timo propietario del(los) bien(es) mueble(s) entregado(s) en garant&iacute;a,
        y que la descripci&oacute;n, caracter&iacute;sticas, calidad y dem&aacute;s especificaciones de los mismos contenidas en el
        contrato, realmente me corresponden y no contienen adulteraciones. A continuaci&oacute;n, se da mayor detalle de los
        bienes:
    </p>

    <table class="bienes">
        <tr>
            <th>N&deg;</th><th>Cant.</th><th>Art&iacute;culo</th><th>Marca</th><th>Modelo</th><th>Serie</th>
        </tr>
        @foreach ($garantias as $i => $bien)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>1</td>
            <td>{{ strtoupper($bien->nombre) }}</td>
            <td>{{ $bien->marca ?? '-' }}</td>
            <td>{{ $bien->modelo ?? '-' }}</td>
            <td>{{ $bien->serie ?? '-' }}</td>
        </tr>
        @endforeach
    </table>

    <p>
        Formulo la presente declaraci&oacute;n en virtud del Principio de Presunci&oacute;n de Veracidad previsto en los
        Art&iacute;culos IV numeral 1.7 y 42&deg; de la Ley del Procedimiento Administrativo General, aprobada por la Ley N&deg;
        27444, sujet&aacute;ndome a las acciones legales y/o penales que correspondan de acuerdo a la legislaci&oacute;n nacional
        vigente.
    </p>

    <p style="text-align: right;">{{ $ciudad }}, {{ $fecha }}.</p>

    <div class="firma-linea">
        {{ $clienteNombre }}<br>
        {{ $tipoDoc }} N&deg;: {{ $credito->cliente->numero_documento }}
    </div>
</body>
</html>
