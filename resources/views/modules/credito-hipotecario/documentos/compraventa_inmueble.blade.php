<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato Privado de Compraventa de Bien Inmueble #{{ $credito->id }}</title>
    <style>
        @page { margin: 4cm 1cm 3cm 3cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1a1a1a; line-height: 1.2; }
        h1 { font-size: 12px; text-align: center; margin: 0; }
        h2 { font-size: 8.6px; margin-top: 4px; margin-bottom: 1px; }
        p { text-align: justify; margin: 2.5px 0; }
        .numero { display: inline-block; border: 1px solid #000; padding: 3px 8px; font-weight: bold; font-size: 10px; }
        ul.datos { margin: 2px 0; padding-left: 18px; }
        ul.datos li { margin: 1px 0; }
        table.cronograma { width: 100%; border-collapse: collapse; margin: 5px 0; }
        table.cronograma th, table.cronograma td { border: 1px solid #999; padding: 2px 4px; font-size: 8px; }
        table.cronograma th { background: #eee; }
        table.firmas { width: 100%; margin-top: 10px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: bottom; }
        .firma-imagen { max-height: 100px; max-width: 140px; }
        .firma-linea { margin-top: 6px; border-top: 1px solid #000; padding-top: 3px; }
        .firma-espacio { height: 72px; }
    </style>
</head>
<body>
    @php
        use App\Nucleo\Support\NumeroALetras;

        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $ciudad = \Illuminate\Support\Str::after($credito->agencia->nombre, 'Agencia ');
        $emp = $credito->empresa;
        $empresaNombre = $emp->razon_social ?: $emp->nombre;
        $clienteNombre = mb_strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido);
        $tipoDoc = mb_strtoupper($credito->cliente->tipo_documento);
        $plural = $garantias->count() > 1;

        $periodicidades = ['diario' => 'diarias', 'semanal' => 'semanales', 'quincenal' => 'quincenales', 'mensual' => 'mensuales'];
        $periodicidad = $periodicidades[$credito->tipo_cuota] ?? $credito->tipo_cuota;

        $datosVendedor = mb_strtoupper($empresaNombre);
        if ($emp->ruc) {
            $datosVendedor .= ', con RUC N°'.$emp->ruc;
        }
        if ($emp->domicilio_legal) {
            $datosVendedor .= ', con domicilio en '.mb_strtoupper($emp->domicilio_legal);
        }
        if ($emp->representante_legal) {
            $datosVendedor .= ', representada por su Representante Legal '.mb_strtoupper($emp->representante_legal);
        }

        $datosComprador = $clienteNombre.', identificado(a) con '.$tipoDoc.' N°'.$credito->cliente->numero_documento;
        if ($credito->cliente->direccion) {
            $datosComprador .= ', con domicilio en '.mb_strtoupper($credito->cliente->direccion);
        }
    @endphp

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="border: none; width: 100px; vertical-align: middle;"></td>
            <td style="border: none; text-align: center; vertical-align: middle;"><h1>CONTRATO PRIVADO DE COMPRAVENTA DE BIEN INMUEBLE</h1></td>
            <td style="border: none; width: 90px; text-align: right; vertical-align: middle;">
                <span class="numero">N&deg; {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}</span>
            </td>
        </tr>
    </table>

    <p>
        Conste por el presente documento el Contrato Privado de Compraventa de Bien Inmueble que celebran de una
        parte, <strong>{{ $datosVendedor }}</strong>, a quien en adelante se le denominará <strong>EL
        VENDEDOR</strong>; y de la otra parte, <strong>{{ $datosComprador }}</strong>, a quien en adelante se le
        denominará <strong>EL COMPRADOR</strong>. El contrato se suscribe en los términos siguientes:
    </p>

    <h2>PRIMERA: OBJETO Y DESCRIPCIÓN {{ $plural ? 'DE LOS INMUEBLES' : 'DEL INMUEBLE' }}</h2>
    <p>
        EL VENDEDOR es propietario {{ $plural ? 'de los inmuebles' : 'del inmueble' }} descrito{{ $plural ? 's' : '' }}
        a continuación:
    </p>
    @foreach ($garantias as $inmueble)
        <ul class="datos">
            <li>Tipo: {{ $inmueble->tipo_inmueble ?? '-' }}</li>
            <li>Dirección: {{ mb_strtoupper($inmueble->direccion) }}</li>
            <li>
                Inscrito en la Partida Electrónica/Ficha N.° {{ mb_strtoupper($inmueble->partida_registral) }}
                del Registro de Predios de la Oficina Registral de {{ $inmueble->oficina_registral ?? '-' }}.
            </li>
        </ul>
    @endforeach
    <p>
        Por el presente documento, EL VENDEDOR vende, cede y transfiere la propiedad {{ $plural ? 'de los inmuebles descritos' : 'del inmueble descrito' }}
        a favor de EL COMPRADOR, con todo lo que por ley le corresponde.
    </p>

    <h2>SEGUNDA: PRECIO Y FORMA DE PAGO EN CUOTAS</h2>
    <p>
        El precio total de la compraventa se fija de mutuo acuerdo en la suma de
        S/ {{ number_format((float) $credito->monto_prestamo, 2) }}
        ({{ NumeroALetras::soles($credito->monto_prestamo) }}). EL COMPRADOR cancelará el precio pactado en
        {{ $credito->numero_cuotas }} cuotas {{ $periodicidad }}, más un interés de
        {{ number_format((float) $credito->interes, 2) }}% sobre el capital, conforme al siguiente cronograma:
    </p>
    @if ($credito->cuotas->isNotEmpty())
        <table class="cronograma">
            <tr><th>N.&deg;</th><th>Vencimiento</th><th>Capital</th><th>Interés</th><th>Cuota</th></tr>
            @foreach ($credito->cuotas as $cuota)
                <tr>
                    <td>{{ $cuota->numero_cuota }}</td>
                    <td>{{ \Illuminate\Support\Carbon::parse($cuota->fecha_vencimiento)->format('d/m/Y') }}</td>
                    <td>S/ {{ number_format((float) $cuota->monto_capital, 2) }}</td>
                    <td>S/ {{ number_format((float) $cuota->monto_interes, 2) }}</td>
                    <td>S/ {{ number_format((float) $cuota->monto_total, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>TERCERA: SANEAMIENTO DE LEY</h2>
    <p>
        EL VENDEDOR declara que sobre {{ $plural ? 'los inmuebles que enajena' : 'el inmueble que enajena' }} no
        pesa ninguna hipoteca, embargo, carga, medida cautelar, litigio ni medida judicial o extrajudicial que
        limite su libre disposición. No obstante, se obliga al saneamiento por evicción conforme a ley.
    </p>

    <h2>CUARTA: ELEVACIÓN A ESCRITURA PÚBLICA</h2>
    <p>
        Las partes acuerdan acudir ante Notario Público para elevar este documento a Escritura Pública e
        inscribirlo en la SUNARP. Todos los gastos notariales, derechos registrales y el impuesto de Alcabala (si
        correspondiera) serán asumidos íntegramente por EL COMPRADOR.
    </p>

    <p style="text-align: right;">{{ $ciudad }}, {{ $fecha }}.</p>

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
                    (EL VENDEDOR)
                </div>
            </td>
            <td>
                <div class="firma-espacio"></div>
                <div class="firma-linea">
                    {{ $clienteNombre }}<br>
                    {{ $tipoDoc }} N.&deg;: {{ $credito->cliente->numero_documento }}<br>
                    (EL COMPRADOR)
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
