<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de Transferencia de Vehículo #{{ $credito->id }}</title>
    <style>
        @page { margin: 4cm 1cm 3cm 3cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.35; }
        h1 { font-size: 12px; text-align: center; margin: 0; }
        h2 { font-size: 9.5px; margin-top: 6px; margin-bottom: 2px; }
        p { text-align: justify; margin: 4px 0; }
        .numero { display: inline-block; border: 1px solid #000; padding: 3px 8px; font-weight: bold; font-size: 10px; }
        ul.datos { margin: 4px 0; padding-left: 18px; }
        ul.datos li { margin: 1px 0; }
        table.firmas { width: 100%; margin-top: 45px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: bottom; }
        .firma-imagen { max-height: 70px; max-width: 220px; }
        .firma-linea { margin-top: 6px; border-top: 1px solid #000; padding-top: 3px; }
        .firma-espacio { height: 60px; }
    </style>
</head>
<body>
    @php
        use App\Nucleo\Support\NumeroALetras;
        use Illuminate\Support\Carbon;
        use Illuminate\Support\Str;

        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $ciudad = Str::after($credito->agencia->nombre, 'Agencia ');
        $emp = $credito->empresa;
        $empresaNombre = $emp->razon_social ?: $emp->nombre;
        $c = $credito->cliente;
        $deudorNombre = mb_strtoupper($c->nombre.' '.$c->apellido);
        $deudorTipoDoc = mb_strtoupper($c->tipo_documento);

        $datosTransferente = mb_strtoupper($empresaNombre);
        if ($emp->ruc) {
            $datosTransferente .= ', identificada con RUC N.º '.$emp->ruc;
        }
        if ($emp->domicilio_legal) {
            $datosTransferente .= ', con domicilio en '.mb_strtoupper($emp->domicilio_legal);
        }
        if ($emp->representante_legal) {
            $datosTransferente .= ', debidamente representada por su Gerente General '.mb_strtoupper($emp->representante_legal);
        }

        $comprador = $datos['comprador_nombre'] ?? '';
        $compradorTipoDoc = mb_strtoupper($datos['comprador_tipo_documento'] ?? '');
        $compradorNumDoc = $datos['comprador_numero_documento'] ?? '';
        $compradorDomicilio = $datos['comprador_domicilio'] ?? null;

        $precio = (string) ($datos['precio_transferencia'] ?? '0');
        $pagosPrevios = collect($datos['pagos_previos'] ?? []);
        $totalPagado = $pagosPrevios->reduce(fn ($carry, $pago) => bcadd($carry, (string) $pago['monto'], 2), '0');
        $saldo = bcsub($precio, $totalPagado, 2);

        $fechaContrato = $credito->fecha_desembolso?->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $plural = $garantias->count() > 1;
    @endphp

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="border: none; width: 100px; vertical-align: middle;"></td>
            <td style="border: none; text-align: center; vertical-align: middle;"><h1>CONTRATO DE TRANSFERENCIA DE VEH&Iacute;CULO POR EJECUCI&Oacute;N DE GARANT&Iacute;A</h1></td>
            <td style="border: none; width: 90px; text-align: right; vertical-align: middle;">
                <span class="numero">N&deg; {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}</span>
            </td>
        </tr>
    </table>

    <p>
        Conste por el presente documento privado de transferencia de propiedad vehicular que celebran de una parte,
        <strong>{{ $datosTransferente }}</strong>, persona jur&iacute;dica debidamente constituida, a quien en adelante se le
        denominar&aacute; <strong>EL TRANSFERENTE</strong>; y de la otra parte, {{ mb_strtoupper($comprador) }}, identificado(a)
        con {{ $compradorTipoDoc }} N&deg; {{ $compradorNumDoc }}@if ($compradorDomicilio), con domicilio en {{ mb_strtoupper($compradorDomicilio) }}@endif,
        a quien en adelante se le denominar&aacute; <strong>EL COMPRADOR</strong>; en los t&eacute;rminos y condiciones
        siguientes:
    </p>

    <h2>PRIMERA: ANTECEDENTES</h2>
    <p>
        1.1. @if ($fechaContrato) Con fecha {{ $fechaContrato }}, @endif EL TRANSFERENTE celebr&oacute; un Contrato de
        Pr&eacute;stamo con Garant&iacute;a con {{ $deudorNombre }}, identificado(a) con {{ $deudorTipoDoc }} N&deg;
        {{ $c->numero_documento }}, mediante el cual se otorg&oacute; un pr&eacute;stamo por la suma de S/
        {{ number_format((float) $credito->monto_prestamo, 2) }} ({{ NumeroALetras::soles($credito->monto_prestamo) }}).
    </p>
    <p>
        1.2. Como garant&iacute;a del cumplimiento de la obligaci&oacute;n asumida,
        {{ $plural ? 'se afectaron los vehículos de propiedad del deudor descritos' : 'se afectó el vehículo de propiedad del deudor descrito' }}
        en la cl&aacute;usula SEGUNDA del presente contrato, facultando expresamente a EL TRANSFERENTE a disponer
        {{ $plural ? 'de los bienes' : 'del bien' }} en caso de incumplimiento de las obligaciones derivadas del pr&eacute;stamo.
    </p>
    <p>
        1.3. Habi&eacute;ndose producido el incumplimiento de pago dentro del plazo convenido, EL TRANSFERENTE ha procedido a
        ejecutar la garant&iacute;a conforme a los t&eacute;rminos pactados, encontr&aacute;ndose facultado para transferir
        {{ $plural ? 'los bienes' : 'el bien' }} a favor de terceros.
    </p>

    <h2>SEGUNDA: IDENTIFICACI&Oacute;N DEL(LOS) VEH&Iacute;CULO(S)</h2>
    <p>
        {{ $plural ? 'Los vehículos materia de transferencia tienen' : 'El vehículo materia de transferencia tiene' }} las
        siguientes caracter&iacute;sticas:
    </p>
    @foreach ($garantias as $vehiculo)
        <ul class="datos">
            <li>Marca: {{ $vehiculo->marca ?? '-' }}</li>
            <li>Modelo: {{ $vehiculo->modelo ?? '-' }}</li>
            <li>Placa de Rodaje: {{ strtoupper($vehiculo->placa) }}</li>
            <li>N&uacute;mero de Serie: {{ $vehiculo->serie ?? '-' }}</li>
            <li>N&uacute;mero de Motor: {{ $vehiculo->motor ?? '-' }}</li>
            <li>Color: {{ $vehiculo->color ?? '-' }}</li>
        </ul>
    @endforeach
    <p>En adelante, denominado{{ $plural ? 's' : '' }} EL VEH&Iacute;CULO{{ $plural ? 'S' : '' }}.</p>

    <h2>TERCERA: OBJETO DEL CONTRATO</h2>
    <p>
        Por el presente acto jur&iacute;dico, EL TRANSFERENTE transfiere en forma definitiva, irrevocable y a t&iacute;tulo
        oneroso a favor de EL COMPRADOR la propiedad, posesi&oacute;n, uso y dem&aacute;s derechos inherentes sobre
        {{ $plural ? 'LOS VEH&Iacute;CULOS' : 'EL VEH&Iacute;CULO' }} descrito{{ $plural ? 's' : '' }} en la cl&aacute;usula
        precedente.
    </p>

    <h2>CUARTA: PRECIO Y FORMA DE PAGO</h2>
    <p>
        Las partes acuerdan que el precio de transferencia asciende a la suma de S/ {{ number_format((float) $precio, 2) }}
        ({{ NumeroALetras::soles($precio) }}).
    </p>
    @if ($pagosPrevios->isNotEmpty())
        <p>
            EL TRANSFERENTE declara haber recibido la suma de S/ {{ number_format((float) $totalPagado, 2) }}
            ({{ NumeroALetras::soles($totalPagado) }}) mediante {{ $pagosPrevios->count() }} dep&oacute;sito(s) realizado(s)
            en las siguientes fechas:
        </p>
        <ul class="datos">
            @foreach ($pagosPrevios as $pago)
                <li>
                    S/ {{ number_format((float) $pago['monto'], 2) }} el
                    {{ Carbon::parse($pago['fecha'])->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y') }}
                </li>
            @endforeach
        </ul>
        <p>
            Por lo que el saldo es de S/ {{ number_format((float) $saldo, 2) }} ({{ NumeroALetras::soles($saldo) }}), el mismo
            que es cancelado a la firma del presente acto jur&iacute;dico a favor de EL TRANSFERENTE, otorgando este
            &uacute;ltimo la m&aacute;s amplia y suficiente constancia de pago y cancelaci&oacute;n.
        </p>
    @else
        <p>
            EL TRANSFERENTE declara haber recibido de EL COMPRADOR la totalidad del precio pactado a la firma del presente
            acto jur&iacute;dico, otorgando la m&aacute;s amplia y suficiente constancia de pago y cancelaci&oacute;n.
        </p>
    @endif

    <h2>QUINTA: ENTREGA DEL(LOS) VEH&Iacute;CULO(S)</h2>
    <p>
        Con la firma del presente documento, EL TRANSFERENTE hace entrega real y efectiva de
        {{ $plural ? 'LOS VEH&Iacute;CULOS' : 'EL VEH&Iacute;CULO' }} a EL COMPRADOR, quien lo{{ $plural ? 's' : '' }} recibe a
        su entera satisfacci&oacute;n, conjuntamente con la documentaci&oacute;n disponible relacionada.
    </p>
    <p>
        Desde la fecha de suscripci&oacute;n del presente contrato, todos los riesgos, responsabilidades civiles,
        administrativas, tributarias y de cualquier otra naturaleza derivadas de la posesi&oacute;n y uso
        {{ $plural ? 'de los vehículos' : 'del vehículo' }} ser&aacute;n asumidas exclusivamente por EL COMPRADOR.
    </p>

    <h2>SEXTA: DECLARACI&Oacute;N DEL COMPRADOR</h2>
    <p>
        EL COMPRADOR declara haber inspeccionado previamente {{ $plural ? 'LOS VEH&Iacute;CULOS' : 'EL VEH&Iacute;CULO' }},
        conocer su estado de conservaci&oacute;n, condiciones mec&aacute;nicas y documentarias, aceptando{{ $plural ? 'los' : 'lo' }}
        en las condiciones en que se encuentra{{ $plural ? 'n' : '' }}, sin reserva alguna.
    </p>

    <h2>S&Eacute;PTIMA: SANEAMIENTO</h2>
    <p>
        EL TRANSFERENTE se obliga al saneamiento por evicci&oacute;n conforme a las disposiciones del C&oacute;digo Civil
        Peruano, dentro de los alcances que correspondan a la presente transferencia.
    </p>

    <h2>OCTAVA: BASE LEGAL DE LA TRANSFERENCIA</h2>
    <p>La presente transferencia se sustenta en:</p>
    <p>
        a) El art&iacute;culo 1354 del C&oacute;digo Civil Peruano, que reconoce la libertad contractual y la
        autonom&iacute;a de la voluntad de las partes.<br>
        b) Los art&iacute;culos 140 y 141 del C&oacute;digo Civil, referidos a la validez y eficacia de los actos
        jur&iacute;dicos.<br>
        c) El Contrato de Pr&eacute;stamo con Garant&iacute;a suscrito con {{ $deudorNombre }}, mediante el cual se
        facult&oacute; expresamente a EL TRANSFERENTE a disponer {{ $plural ? 'de los vehículos otorgados' : 'del vehículo otorgado' }}
        en garant&iacute;a ante el incumplimiento de la obligaci&oacute;n garantizada.<br>
        d) El Decreto Legislativo N.&deg; 1400, Decreto Legislativo que aprueba el R&eacute;gimen de Garant&iacute;a
        Mobiliaria, que reconoce el derecho del acreedor garantizado a ejecutar la garant&iacute;a y disponer del bien
        afectado cuando se produzca el incumplimiento de la obligaci&oacute;n garantizada, conforme a lo pactado por las
        partes.<br>
        e) Las normas registrales y disposiciones aplicables para la transferencia de propiedad vehicular ante la
        Superintendencia Nacional de los Registros P&uacute;blicos &ndash; SUNARP.
    </p>

    <h2>NOVENA: ELEVACI&Oacute;N A ESCRITURA P&Uacute;BLICA</h2>
    <p>
        Cualquiera de las partes podr&aacute; solicitar la elevaci&oacute;n del presente contrato a escritura
        p&uacute;blica, oblig&aacute;ndose ambas a suscribir los documentos adicionales que resulten necesarios para su
        formalizaci&oacute;n e inscripci&oacute;n registral.
    </p>

    <h2>D&Eacute;CIMA: DOMICILIO Y JURISDICCI&Oacute;N</h2>
    <p>
        Para todos los efectos derivados del presente contrato, las partes se someten a la competencia de los jueces y
        tribunales del Distrito Judicial correspondiente a {{ $ciudad }}, renunciando a cualquier otro fuero que pudiera
        corresponderles.
    </p>

    <p>
        En se&ntilde;al de conformidad, las partes firman el presente documento por duplicado, en la ciudad de
        {{ $ciudad }}, con fecha {{ $fecha }}.
    </p>

    <table class="firmas">
        <tr>
            <td>
                @if ($emp->firma_path)
                    <img class="firma-imagen" src="{{ $fotoDataUri($emp->firma_path, 600) }}">
                @else
                    <div class="firma-espacio"></div>
                @endif
                <div class="firma-linea">
                    {{ mb_strtoupper($empresaNombre) }}<br>
                    @if ($emp->ruc) RUC: {{ $emp->ruc }}<br> @endif
                    (Transferente)
                </div>
            </td>
            <td>
                <div class="firma-espacio"></div>
                <div class="firma-linea">
                    {{ mb_strtoupper($comprador) }}<br>
                    {{ $compradorTipoDoc }} N&deg;: {{ $compradorNumDoc }}<br>
                    (Comprador)
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
