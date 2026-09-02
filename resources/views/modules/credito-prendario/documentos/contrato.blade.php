<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de Préstamo con Garantía #{{ $credito->id }}</title>
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
        if ($credito->empresa->actividad_economica) {
            $datosAcreedor .= ', con actividad económica '.strtoupper($credito->empresa->actividad_economica);
        }
        $datosAcreedor .= ', persona jurídica debidamente constituida';
        if ($credito->empresa->representante_legal) {
            $datosAcreedor .= ', así también se encuentra debidamente representada por su gerente general '.strtoupper($credito->empresa->representante_legal);
        }

        $datosDeudor = $clienteNombre.' con '.$tipoDoc.' N°'.$credito->cliente->numero_documento;
        if ($credito->cliente->direccion) {
            $datosDeudor .= ' con domicilio, '.strtoupper($credito->cliente->direccion);
        }
    @endphp

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="border: none; width: 100px; vertical-align: middle;">
                @if ($credito->empresa->logo_path)
                    <img class="logo" src="{{ $fotoDataUri($credito->empresa->logo_path, 300) }}">
                @endif
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;"><h1>CONTRATO DE PR&Eacute;STAMO CON GARANT&Iacute;A</h1></td>
            <td style="border: none; width: 90px; text-align: right; vertical-align: middle;">
                <span class="numero">N&deg; {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}</span>
            </td>
        </tr>
    </table>

    <p>
        En la ciudad de {{ $ciudad }}, con fecha {{ $fecha }}; consta el presente contrato, por una parte,
        <strong>EL ACREEDOR</strong>, la empresa <strong>{{ $datosAcreedor }}</strong>, y por la otra parte interviniente el
        <strong>DEUDOR</strong>, {{ $datosDeudor }}.
    </p>
    <p><strong>LOS CONTRATANTES</strong>, celebran el presente contrato bajo las siguientes cl&aacute;usulas:</p>

    <h2>PRIMERO: OBJETO DEL CONTRATO</h2>
    <p>
        El ACREEDOR, concede y otorga como PR&Eacute;STAMO DE DINERO a favor del DEUDOR, la suma de
        S/ {{ number_format($credito->monto_prestamo, 2) }}, dicho importe es recibido en su totalidad, EN EFECTIVO, a la firma
        del presente contrato, siendo as&iacute; que el DEUDOR se somete a todas las obligaciones que emanan del presente
        CONTRATO DE PR&Eacute;STAMO CON GARANT&Iacute;A.
    </p>

    <h2>SEGUNDO: PLAZO Y MODALIDAD</h2>
    <p>
        2.1. El pr&eacute;stamo de dinero es recibido por el deudor en efectivo a la firma del presente contrato, la misma que
        deber&aacute; ser devuelta en el PLAZO de {{ $credito->plazo_dias }} D&Iacute;AS CALENDARIO computables a partir de la
        fecha de firma del presente CONTRATO.
    </p>
    <p>
        2.2. Que, las partes acuerdan y estipulan que el pago ser&aacute; &iacute;ntegro del capital PRESTADO de
        S/ {{ number_format($credito->monto_prestamo, 2) }}, m&aacute;s un pago adicional equivalente al
        {{ number_format($credito->interes, 2) }}% del monto del capital PRESTADO, por concepto de intereses.
    </p>
    <p>
        2.3. Que, el pr&eacute;stamo realizado se devolver&aacute; en una sola armada, teniendo as&iacute; que EL DEUDOR
        devolver&aacute; en su totalidad a la caducidad del plazo establecido en la cl&aacute;usula anterior, la suma de
        S/ {{ number_format($credito->monto_prestamo, 2) }}, m&aacute;s los intereses pactados entre las partes.
    </p>

    <h2>TERCERO: CONSTITUCI&Oacute;N DE GARANT&Iacute;A</h2>
    <p>
        3.1. EL DEUDOR entrega como garant&iacute;a el(los) bien(es) mueble(s) descrito(s) en la cl&aacute;usula posterior, y lo
        hace en su CONDICI&Oacute;N DE PROPIETARIO, derecho que acredita con documento id&oacute;neo, salvo en caso de fuerza
        mayor (p&eacute;rdida de boleta o factura), deber&aacute; firmar una declaraci&oacute;n jurada, donde DECLARE SER EL
        LEG&Iacute;TIMO PROPIETARIO DEL(LOS) BIEN(ES) MUEBLE(S) entregado(s) en garant&iacute;a, al amparo de la Ley del
        Procedimiento Administrativo General Ley N&deg; 27444, bajo su &uacute;nica y absoluta responsabilidad en caso de
        declaraci&oacute;n falsa, sea sometida a una denuncia por el presunto delito de FALSEDAD GEN&Eacute;RICA.
    </p>
    <p>
        3.2. El(los) bien(es) entregado(s) a EL ACREEDOR tiene(n) la calificaci&oacute;n de bien de segundo uso para los
        efectos del presente contrato y por el riesgo le son aplicables factores de depreciaci&oacute;n por marca, modelo y
        antig&uuml;edad, deterioro de vida &uacute;til, entre otros que acepta EL DEUDOR.
    </p>

    <h2>CUARTA: DISPOSICI&Oacute;N DEL BIEN POR INCUMPLIMIENTO</h2>
    <p>
        EL ACREEDOR tendr&aacute; derecho de disponer del(los) bien(es) mueble(s) ante el INCUMPLIMIENTO DE LA DEVOLUCI&Oacute;N
        DEL PR&Eacute;STAMO DE DINERO, pudiendo hacer uso de su derecho de retroventa del(los) bien(es) otorgado(s) en
        garant&iacute;a, bajo las siguientes condiciones:
    </p>
    <p>
        4.1. Ante el vencimiento del plazo pactado entre las partes en el presente contrato, con una &uacute;nica pr&oacute;rroga
        que deber&aacute; ser solicitada por EL DEUDOR, por escrito, antes del vencimiento del plazo acordado entre las partes.
    </p>
    <p>
        4.2. Ante el vencimiento del plazo pactado y la no comunicaci&oacute;n por escrito por parte de EL DEUDOR y/o la no
        comunicaci&oacute;n del refinanciamiento dentro del plazo de gracia, EL ACREEDOR podr&aacute; disponer en su totalidad
        del(los) bien(es) mueble(s).
    </p>

    <h2>QUINTA: COMPROMISO DE PAGO POR PARTE DEL DEUDOR</h2>
    <p>
        NOMBRES: {{ $clienteNombre }}, con {{ $tipoDoc }} N&deg; {{ $credito->cliente->numero_documento }} me comprometo si a la
        fecha pactada no recojo el(los) bien(es) dejado(s) en garant&iacute;a, pagando la deuda en su totalidad m&aacute;s
        intereses y/o presento el escrito de pr&oacute;rroga y/o refinanciamiento dentro del plazo de gracia, SALDR&Aacute; AL
        REMATE, asimismo acepto las cl&aacute;usulas del presente contrato, dando mi conformidad con mi firma en el presente
        contrato y huella dactilar.
    </p>

    <h2>SEXTA: CARACTER&Iacute;STICAS DEL(LOS) BIEN(ES) DEJADO(S) EN GARANT&Iacute;A</h2>
    <table class="bienes">
        <tr>
            <th>N&deg;</th><th>Cant.</th><th>Art&iacute;culo</th><th>Marca</th><th>Modelo</th><th>Serie</th><th>Valorizaci&oacute;n</th>
        </tr>
        @foreach ($garantias as $i => $bien)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>1</td>
            <td>{{ strtoupper($bien->nombre) }}</td>
            <td>{{ $bien->marca ?? '-' }}</td>
            <td>{{ $bien->modelo ?? '-' }}</td>
            <td>{{ $bien->serie ?? '-' }}</td>
            <td>S/ {{ number_format($bien->valorizacion, 2) }}</td>
        </tr>
        @endforeach
        <tr>
            <td colspan="6" style="text-align:right; font-weight:bold;">Total valorizaci&oacute;n</td>
            <td style="font-weight:bold;">S/ {{ number_format($garantias->sum('valorizacion'), 2) }}</td>
        </tr>
    </table>

    <p>
        Que, en el presente contrato las partes declaran libremente que NO EXISTE SIMULACI&Oacute;N, FALTA DE VOLUNTAD E
        INCAPACIDAD DE NINGUNA DE LAS PARTES CONTRATANTES.
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
