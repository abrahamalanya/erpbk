<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de Venta a Crédito #{{ $venta->id }}</title>
    <style>
        @page { margin: 2.5cm 1.5cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1a1a1a; line-height: 1.35; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 14px; }
        h2 { font-size: 10px; margin-top: 10px; margin-bottom: 3px; }
        p { text-align: justify; margin: 4px 0; }
        ul.partes { margin: 4px 0; padding-left: 18px; }
        ul.partes li { margin: 3px 0; text-align: justify; }
        table.datos-articulo { width: 100%; border-collapse: collapse; margin: 6px 0; }
        table.datos-articulo td { padding: 2px 4px; vertical-align: top; }
        table.datos-articulo td.label { width: 150px; color: #555; }
        table.cronograma { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.cronograma th, table.cronograma td { border: 1px solid #ccc; padding: 3px 5px; font-size: 10px; }
        table.cronograma th { background: #f2f2f2; }
        table.firmas { width: 100%; margin-top: 45px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: bottom; }
        .firma-imagen { max-height: 130px; max-width: 220px; }
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
        $ciudad = $venta->agencia ? Str::after($venta->agencia->nombre, 'Agencia ') : null;

        $periodicidades = ['diario' => 'diarias', 'semanal' => 'semanales', 'quincenal' => 'quincenales', 'mensual' => 'mensuales'];
        $periodicidad = $periodicidades[$venta->tipo_cuota] ?? $venta->tipo_cuota;
        $saldoFinanciado = (float) $venta->precio_venta - (float) $venta->inicial;

        $datosVendedor = mb_strtoupper($empresaNombre);
        if ($emp->ruc) {
            $datosVendedor .= ', con RUC N°'.$emp->ruc;
        }
        if ($emp->domicilio_legal) {
            $datosVendedor .= ', domiciliado en '.mb_strtoupper($emp->domicilio_legal);
        }
        if ($emp->representante_legal) {
            $datosVendedor .= ', con Representante Legal el/la Sr.(a) '.mb_strtoupper($emp->representante_legal);
        }

        $datosComprador = $clienteNombre.', identificado(a) con '.$tipoDoc.' N°'.$c->numero_documento;
        if ($c->direccion) {
            $datosComprador .= ', con domicilio en '.mb_strtoupper($c->direccion);
        }
    @endphp

    <h1>CONTRATO DE VENTA A CRÉDITO DE ARTEFACTOS N.° {{ str_pad((string) $venta->id, 6, '0', STR_PAD_LEFT) }}</h1>

    <p>Conste por el presente documento el contrato de venta a crédito que celebran de una parte:</p>
    <ul class="partes">
        <li><strong>EL VENDEDOR:</strong> {{ $datosVendedor }}.</li>
        <li><strong>EL COMPRADOR:</strong> {{ $datosComprador }}.</li>
    </ul>
    <p>El contrato se celebra bajo los términos y condiciones siguientes:</p>

    <h2>PRIMERA: OBJETO DEL CONTRATO</h2>
    <p>
        EL VENDEDOR da en venta real y enajenación perpetua a favor de EL COMPRADOR, y este acepta a su entera
        conformidad, el siguiente artefacto:
    </p>
    <table class="datos-articulo">
        <tr><td class="label">Tipo de artefacto</td><td>{{ mb_strtoupper($articulo->tipo ?? $articulo->nombre ?? '—') }}</td></tr>
        @if($articulo->nombre)
        <tr><td class="label">Descripción</td><td>{{ mb_strtoupper($articulo->nombre) }}</td></tr>
        @endif
        <tr><td class="label">Marca / Modelo / Serie</td><td>{{ $articulo->marca ?? '—' }} / {{ $articulo->modelo ?? '—' }} / {{ $articulo->serie ?? '—' }}</td></tr>
        <tr><td class="label">Estado</td><td>Nuevo ( &nbsp; )&nbsp;&nbsp;&nbsp;Usado ( &nbsp; )</td></tr>
    </table>

    <h2>SEGUNDA: DEL PRECIO Y FORMA DE PAGO</h2>
    <p>
        El precio total pactado por el artefacto es de S/ {{ number_format((float) $venta->precio_venta, 2) }}
        ({{ NumeroALetras::soles($venta->precio_venta) }}), el cual EL COMPRADOR cancelará de la siguiente manera:
        una cuota inicial (enganche) de S/ {{ number_format((float) $venta->inicial, 2) }}
        ({{ NumeroALetras::soles($venta->inicial) }}), que entrega en este acto; y un saldo financiado de
        S/ {{ number_format($saldoFinanciado, 2) }} ({{ NumeroALetras::soles($saldoFinanciado) }}), que se
        cancelará en {{ $venta->numero_cuotas }} cuotas {{ $periodicidad }}
        @if((float) $venta->interes > 0) (con una tasa de interés de {{ $venta->interes }}% mensual) @else (sin interés) @endif,
        conforme al siguiente cronograma:
    </p>

    <table class="cronograma">
        <thead>
            <tr><th>N.°</th><th>Vencimiento</th><th>Capital</th><th>Interés</th><th>Total</th></tr>
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

    <h2>TERCERA: INTERESES Y MORA</h2>
    <p>
        En caso de que EL COMPRADOR incurra en retraso en el pago de una o más cuotas, se aplicará un interés
        moratorio sobre el saldo vencido, sin perjuicio del derecho de EL VENDEDOR de exigir la resolución del
        presente contrato o la devolución inmediata del artefacto.
    </p>

    <h2>CUARTA: RESERVA DE PROPIEDAD</h2>
    <p>
        Conforme al artículo 1583 del Código Civil Peruano, EL VENDEDOR se reserva la propiedad del artefacto
        objeto de la presente venta hasta que EL COMPRADOR haya cancelado el 100% del precio pactado. En
        consecuencia, EL COMPRADOR no podrá vender, empeñar, transferir ni gravar el artefacto sin autorización
        escrita de EL VENDEDOR.
    </p>

    <h2>QUINTA: SOLUCIÓN DE CONFLICTOS</h2>
    <p>
        Para cualquier controversia derivada del cumplimiento del presente contrato, ambas partes se someten
        expresamente a la jurisdicción de los jueces y tribunales @if($ciudad) de {{ $ciudad }} @endif.
    </p>

    <p>
        En señal de conformidad, las partes firman el presente documento por duplicado
        @if($ciudad) en {{ $ciudad }}, @endif con fecha {{ $fecha }}.
    </p>

    <table class="firmas">
        <tr>
            <td>
                @if($emp->firma_path)
                    <img class="firma-imagen" src="{{ $fotoDataUri($emp->firma_path, 600) }}">
                @else
                    <div class="firma-espacio"></div>
                @endif
                <div class="firma-linea">
                    {{ mb_strtoupper($empresaNombre) }}<br>
                    @if($emp->ruc) RUC: {{ $emp->ruc }}<br> @endif
                    (EL VENDEDOR)
                </div>
            </td>
            <td>
                <div class="firma-espacio"></div>
                <div class="firma-linea">
                    {{ $clienteNombre }}<br>
                    {{ $tipoDoc }} N.°: {{ $c->numero_documento }}<br>
                    (EL COMPRADOR)
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
