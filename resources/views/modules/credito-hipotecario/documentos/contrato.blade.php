<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de Préstamo con Garantía Hipotecaria #{{ $credito->id }}</title>
    <style>
        @page { margin: 11mm 13mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.3px; color: #1a1a1a; line-height: 1.28; }
        h1 { font-size: 13px; text-align: center; margin: 0; }
        h2 { font-size: 9.8px; margin-top: 7px; margin-bottom: 2px; }
        p { text-align: justify; margin: 4px 0; }
        .numero { display: inline-block; border: 1px solid #000; padding: 3px 8px; font-weight: bold; font-size: 10px; }
        .logo { max-height: 42px; max-width: 100px; }
        table.bienes { width: 100%; border-collapse: collapse; margin: 5px 0; }
        table.bienes th, table.bienes td { border: 1px solid #999; padding: 2px 4px; font-size: 8px; }
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
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $ciudad = \Illuminate\Support\Str::after($credito->agencia->nombre, 'Agencia ');
        $empresaNombre = $credito->empresa->razon_social ?: $credito->empresa->nombre;
        $clienteNombre = strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido);
        $tipoDoc = strtoupper($credito->cliente->tipo_documento);

        $datosAcreedor = strtoupper($empresaNombre);
        if ($credito->empresa->ruc) {
            $datosAcreedor .= ', con R.U.C N°'.$credito->empresa->ruc;
        }
        if ($credito->empresa->domicilio_legal) {
            $datosAcreedor .= ', con domicilio legal en '.strtoupper($credito->empresa->domicilio_legal);
        }
        $datosAcreedor .= ', persona jurídica debidamente constituida';
        if ($credito->empresa->representante_legal) {
            $datosAcreedor .= ', representada por su gerente general '.strtoupper($credito->empresa->representante_legal);
        }

        $datosDeudor = $clienteNombre.' con '.$tipoDoc.' N°'.$credito->cliente->numero_documento;
        if ($credito->cliente->direccion) {
            $datosDeudor .= ', con domicilio en '.strtoupper($credito->cliente->direccion);
        }
        if ($credito->cliente->referencia) {
            $datosDeudor .= ' (referencia: '.strtoupper($credito->cliente->referencia).')';
        }
    @endphp

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="border: none; width: 100px; vertical-align: middle;">
                @if ($credito->empresa->logo_path)
                    <img class="logo" src="{{ $fotoDataUri($credito->empresa->logo_path, 300) }}">
                @endif
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;"><h1>CONTRATO DE PR&Eacute;STAMO CON GARANT&Iacute;A HIPOTECARIA</h1></td>
            <td style="border: none; width: 90px; text-align: right; vertical-align: middle;">
                <span class="numero">N&deg; {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}</span>
            </td>
        </tr>
    </table>

    <p>
        En la ciudad de {{ $ciudad }}, con fecha {{ $fecha }}; consta el presente contrato, por una parte,
        <strong>EL ACREEDOR</strong>, la empresa <strong>{{ $datosAcreedor }}</strong>, y por la otra parte el
        <strong>DEUDOR</strong>, {{ $datosDeudor }}.
    </p>
    <p><strong>LOS CONTRATANTES</strong> celebran el presente contrato bajo las siguientes cl&aacute;usulas:</p>

    <h2>PRIMERO: OBJETO DEL CONTRATO</h2>
    <p>
        El ACREEDOR concede y otorga como PR&Eacute;STAMO DE DINERO a favor del DEUDOR la suma de
        S/ {{ number_format($credito->monto_prestamo, 2) }}, importe recibido en su totalidad a la firma del presente
        contrato, quedando el DEUDOR sometido a todas las obligaciones que emanan de este CONTRATO DE PR&Eacute;STAMO CON
        GARANT&Iacute;A HIPOTECARIA.
    </p>

    <h2>SEGUNDO: PLAZO Y MODALIDAD</h2>
    <p>
        2.1. El pr&eacute;stamo deber&aacute; ser devuelto en el PLAZO de {{ $credito->plazo_dias }} D&Iacute;AS CALENDARIO
        computables a partir de la fecha de firma del presente CONTRATO.
    </p>
    <p>
        2.2. El pago ser&aacute; &iacute;ntegro del capital PRESTADO de S/ {{ number_format($credito->monto_prestamo, 2) }},
        m&aacute;s un pago adicional equivalente al {{ number_format($credito->interes, 2) }}% del capital por concepto de
        intereses.
    </p>

    <h2>TERCERO: CONSTITUCI&Oacute;N DE GARANT&Iacute;A HIPOTECARIA</h2>
    <p>
        3.1. EL DEUDOR constituye HIPOTECA a favor de EL ACREEDOR sobre el(los) inmueble(s) descrito(s) en la cl&aacute;usula
        QUINTA, en su CONDICI&Oacute;N DE PROPIETARIO seg&uacute;n la(s) PARTIDA(S) REGISTRAL(ES) SUNARP indicada(s). Esta
        hipoteca garantiza el capital, sus intereses, moras, gastos y costos derivados del presente contrato.
    </p>
    <p>
        3.2. EL DEUDOR declara que el(los) inmueble(s) se encuentra(n) libre(s) de todo gravamen, carga, embargo o medida
        judicial, salvo lo expresamente consignado, y autoriza a EL ACREEDOR a verificar su situaci&oacute;n registral y a
        inscribir la presente hipoteca en SUNARP.
    </p>

    <h2>CUARTA: EJECUCI&Oacute;N DE LA HIPOTECA POR INCUMPLIMIENTO</h2>
    <p>
        4.1. Ante el vencimiento del plazo pactado, con una &uacute;nica pr&oacute;rroga solicitada por escrito por EL DEUDOR
        antes del vencimiento, EL ACREEDOR podr&aacute; ejecutar la hipoteca y disponer del(los) inmueble(s).
    </p>
    <p>
        4.2. La venta del(los) inmueble(s) rematado(s) se efectuar&aacute; previa CONFORMIDAD del NOTARIO y/o ABOGADO
        designado, dejando constancia documental antes de su publicaci&oacute;n en la tienda.
    </p>

    <h2>QUINTA: DESCRIPCI&Oacute;N DEL(LOS) INMUEBLE(S) HIPOTECADO(S)</h2>
    <table class="bienes">
        <tr>
            <th>N&deg;</th><th>Partida registral</th><th>Oficina</th><th>Tipo</th><th>Direcci&oacute;n</th>
            <th>Distrito</th><th>&Aacute;rea terreno</th><th>&Aacute;rea construida</th><th>Propietario</th><th>Valorizaci&oacute;n</th>
        </tr>
        @foreach ($garantias as $i => $inmueble)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ strtoupper($inmueble->partida_registral) }}</td>
            <td>{{ $inmueble->oficina_registral ?? '-' }}</td>
            <td>{{ $inmueble->tipo_inmueble ?? '-' }}</td>
            <td>{{ strtoupper($inmueble->direccion) }}</td>
            <td>{{ $inmueble->distrito ?? '-' }}</td>
            <td>{{ $inmueble->area_terreno ? number_format($inmueble->area_terreno, 2).' m&sup2;' : '-' }}</td>
            <td>{{ $inmueble->area_construida ? number_format($inmueble->area_construida, 2).' m&sup2;' : '-' }}</td>
            <td>{{ strtoupper($inmueble->propietario ?? '-') }}</td>
            <td>S/ {{ number_format($inmueble->valorizacion, 2) }}</td>
        </tr>
        @endforeach
        <tr>
            <td colspan="9" style="text-align:right; font-weight:bold;">Total valorizaci&oacute;n</td>
            <td style="font-weight:bold;">S/ {{ number_format($garantias->sum('valorizacion'), 2) }}</td>
        </tr>
    </table>

    <h2>SEXTA: COMPROMISO DE PAGO</h2>
    <p>
        NOMBRES: {{ $clienteNombre }}, con {{ $tipoDoc }} N&deg; {{ $credito->cliente->numero_documento }}, me comprometo a
        que, si a la fecha pactada no cancelo la deuda en su totalidad m&aacute;s intereses y/o no presento el escrito de
        pr&oacute;rroga y/o refinanciamiento dentro del plazo de gracia, EL ACREEDOR podr&aacute; ejecutar la hipoteca y el(los)
        inmueble(s) SALDR&Aacute;(N) AL REMATE. Acepto las cl&aacute;usulas del presente contrato dando conformidad con mi
        firma y huella dactilar.
    </p>

    @if ($credito->supervisadoPor)
    <p>Operaci&oacute;n supervisada por: <strong>{{ strtoupper($credito->supervisadoPor->name) }}</strong>.</p>
    @endif

    <p>
        Las partes declaran libremente que NO EXISTE SIMULACI&Oacute;N, FALTA DE VOLUNTAD NI INCAPACIDAD DE NINGUNA DE LAS
        PARTES CONTRATANTES.
    </p>

    <p style="text-align: right;">{{ $ciudad }}, {{ $fecha }}.</p>

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
