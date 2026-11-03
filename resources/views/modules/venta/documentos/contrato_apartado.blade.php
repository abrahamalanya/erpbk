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
        ul.condiciones { margin: 4px 0; padding-left: 18px; }
        ul.condiciones li { margin: 3px 0; text-align: justify; }
        table.datos-articulo { width: 100%; border-collapse: collapse; margin: 6px 0; }
        table.datos-articulo td { padding: 2px 4px; vertical-align: top; }
        table.datos-articulo td.label { width: 150px; color: #555; }
        table.firmas { width: 100%; margin-top: 45px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: bottom; }
        .firma-imagen { max-height: 150px; max-width: 220px; }
        .firma-espacio { height: 60px; }
        .firma-linea { margin-top: 6px; border-top: 1px solid #000; padding-top: 3px; }
    </style>
</head>
<body>
    @php
        use App\Nucleo\Support\NumeroALetras;
        use Illuminate\Support\Str;

        $emp = $venta->empresa;
        $empresaNombre = $emp->razon_social ?: $emp->nombre;
        $c = $venta->cliente;
        $clienteNombre = mb_strtoupper($c->nombre.' '.$c->apellido);
        $tipoDoc = mb_strtoupper($c->tipo_documento);
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $fechaLimite = $venta->fecha_limite->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $ciudad = $venta->agencia ? Str::after($venta->agencia->nombre, 'Agencia ') : null;

        $datosEstablecimiento = mb_strtoupper($empresaNombre);
        if ($emp->ruc) {
            $datosEstablecimiento .= ', con RUC N°'.$emp->ruc;
        }
        if ($emp->domicilio_legal) {
            $datosEstablecimiento .= ', con domicilio en '.mb_strtoupper($emp->domicilio_legal);
        }
        if ($emp->representante_legal) {
            $datosEstablecimiento .= ', representada por el/la Sr.(a) '.mb_strtoupper($emp->representante_legal);
        }

        $datosCliente = $clienteNombre.', con '.$tipoDoc.' N°'.$c->numero_documento;
        if ($c->direccion) {
            $datosCliente .= ', domiciliado(a) en '.mb_strtoupper($c->direccion);
        }
    @endphp

    <h1>CONTRATO DE RESERVA Y APARTADO DE BIENES N.° {{ str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT) }}</h1>

    <p>
        Conste por el presente documento el Contrato de Apartado y Reserva que celebran de una parte
        <strong>{{ $datosEstablecimiento }}</strong>, a quien en adelante se le denominará <strong>EL
        ESTABLECIMIENTO</strong>; y de la otra parte <strong>{{ $datosCliente }}</strong>, a quien en adelante se le
        denominará <strong>EL CLIENTE</strong>; bajo las condiciones siguientes:
    </p>

    <h2>PRIMERA: OBJETO</h2>
    <p>EL CLIENTE solicita la reserva y apartado del siguiente bien/producto:</p>
    <table class="datos-articulo">
        <tr><td class="label">Descripción</td><td>{{ mb_strtoupper($articulo->nombre ?? $articulo->tipo ?? '—') }}</td></tr>
        <tr><td class="label">Marca / Modelo / Serie</td><td>{{ $articulo->marca ?? '—' }} / {{ $articulo->modelo ?? '—' }} / {{ $articulo->serie ?? '—' }}</td></tr>
        <tr><td class="label">Precio total del producto</td><td>S/ {{ number_format((float) $venta->precio_venta, 2) }} ({{ NumeroALetras::soles($venta->precio_venta) }})</td></tr>
    </table>

    <h2>SEGUNDA: MONTO DEL APARTADO Y SALDO</h2>
    <p>
        EL CLIENTE entrega en este acto la suma de S/ {{ number_format((float) $venta->inicial, 2) }}
        ({{ NumeroALetras::soles($venta->inicial) }}) como concepto de adelanto, arras y separación del producto. El
        saldo restante asciende a S/ {{ number_format((float) $venta->saldo_pendiente, 2) }}, el cual EL CLIENTE se
        obliga a cancelar mediante uno o más abonos.
    </p>

    <h2>TERCERA: PLAZO DE VIGENCIA</h2>
    <p>
        EL ESTABLECIMIENTO se compromete a retirar el bien de la exhibición y guardarlo a favor de EL CLIENTE hasta
        el <strong>{{ $fechaLimite }}</strong>, fecha límite improrrogable para la cancelación del saldo y el retiro
        del producto.
    </p>

    <h2>CUARTA: INCUMPLIMIENTO Y PENALIDAD</h2>
    <p>Si vencido el plazo fijado en la cláusula anterior EL CLIENTE no cancela el saldo restante:</p>
    <ul class="condiciones">
        <li>El presente contrato queda resuelto de pleno derecho, sin necesidad de aviso previo.</li>
        <li>EL ESTABLECIMIENTO pondrá nuevamente el bien a la venta.</li>
        <li>
            EL CLIENTE pierde, por concepto de penalidad por almacenamiento y reserva, el inicial y los abonos
            entregados a favor de EL ESTABLECIMIENTO.
        </li>
    </ul>

    <p>
        En señal de conformidad, las partes firman el presente documento por duplicado
        @if($ciudad) en {{ $ciudad }}, @endif con fecha {{ $fecha }}.
    </p>

    <table class="firmas">
        <tr>
            <td>
                <div class="firma-espacio"></div>
                <div class="firma-linea">
                    {{ $clienteNombre }}<br>
                    {{ $tipoDoc }} N.°: {{ $c->numero_documento }}<br>
                    (EL CLIENTE)
                </div>
            </td>
            <td>
                @if($emp->firma_path)
                    <img class="firma-imagen" src="{{ $fotoDataUri($emp->firma_path, 600) }}">
                @else
                    <div class="firma-espacio"></div>
                @endif
                <div class="firma-linea">
                    {{ mb_strtoupper($empresaNombre) }}<br>
                    @if($emp->ruc) RUC: {{ $emp->ruc }}<br> @endif
                    (EL ESTABLECIMIENTO)
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
