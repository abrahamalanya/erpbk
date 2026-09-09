<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Carta de No Adeudo #{{ $credito->id }}</title>
    <style>
        @page { margin: 4cm 1cm 3cm 3cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.4; }
        h1 { font-size: 14px; text-align: center; margin: 6px 0 2px; letter-spacing: 1px; }
        p { text-align: justify; margin: 8px 0; }
        .numero { display: inline-block; border: 1px solid #000; padding: 3px 8px; font-weight: bold; font-size: 10px; }
        .logo { max-height: 44px; max-width: 110px; }
        table.garantias { width: 100%; border-collapse: collapse; margin: 6px 0; }
        table.garantias th, table.garantias td { border: 1px solid #999; padding: 3px 5px; font-size: 9px; }
        table.garantias th { background: #eee; }
        .firma { margin-top: 60px; text-align: center; width: 60%; margin-left: auto; margin-right: auto; }
        .firma-imagen { max-height: 55px; max-width: 180px; }
        .firma-linea { margin-top: 6px; border-top: 1px solid #000; padding-top: 4px; }
    </style>
</head>
<body>
    @php
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $ciudad = \Illuminate\Support\Str::after($credito->agencia->nombre, 'Agencia ');
        $empresaNombre = $credito->empresa->razon_social ?: $credito->empresa->nombre;
        $clienteNombre = strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido);
        $tipoDoc = strtoupper($credito->cliente->tipo_documento);
    @endphp

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="border: none; width: 110px; vertical-align: middle;">
                @if ($credito->empresa->logo_path)
                    <img class="logo" src="{{ $fotoDataUri($credito->empresa->logo_path, 300) }}">
                @endif
            </td>
            <td style="border: none; text-align: center; vertical-align: middle;">
                <h1>CARTA DE NO ADEUDO</h1>
            </td>
            <td style="border: none; width: 95px; text-align: right; vertical-align: middle;">
                <span class="numero">N&deg; {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}</span>
            </td>
        </tr>
    </table>

    <p style="text-align: right; margin-top: 14px;">{{ $ciudad }}, {{ $fecha }}</p>

    <p>A quien corresponda:</p>

    <p>
        Por medio de la presente, <strong>{{ strtoupper($empresaNombre) }}</strong>
        @if ($credito->empresa->ruc) con RUC N&deg; {{ $credito->empresa->ruc }} @endif
        deja constancia de que el(la) se&ntilde;or(a) <strong>{{ $clienteNombre }}</strong>, identificado(a) con
        {{ $tipoDoc }} N&deg; {{ $credito->cliente->numero_documento }}, ha <strong>cancelado en su integridad</strong>
        el cr&eacute;dito {{ strtoupper($credito->tipo_credito) }} N&deg;
        {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}, otorgado por la suma de
        S/ {{ number_format($credito->monto_prestamo, 2) }}
        @if ($credito->fecha_desembolso)
            desembolsado el {{ $credito->fecha_desembolso->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y') }}
        @endif.
    </p>

    <p>
        En consecuencia, a la fecha el(la) cliente <strong>no mantiene deuda ni obligaci&oacute;n pendiente alguna</strong>
        con {{ strtoupper($empresaNombre) }} derivada del cr&eacute;dito antes referido, quedando &eacute;ste
        totalmente extinguido.
    </p>

    @if ($garantias->isNotEmpty())
        <p>Las garant&iacute;as que respaldaban el cr&eacute;dito, y que han sido devueltas al cliente, son:</p>
        <table class="garantias">
            <tr><th>N&deg;</th><th>Descripci&oacute;n</th><th>C&oacute;digo</th></tr>
            @foreach ($garantias as $i => $g)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ strtoupper($g->nombre) }}</td>
                    <td>{{ $g->codigo ?? '-' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p>Se expide la presente carta a solicitud del interesado para los fines que estime convenientes.</p>

    <div class="firma">
        @if ($credito->empresa->firma_path)
            <img class="firma-imagen" src="{{ $fotoDataUri($credito->empresa->firma_path, 400) }}">
        @endif
        <div class="firma-linea">
            {{ strtoupper($empresaNombre) }}<br>
            @if ($credito->empresa->ruc) RUC: {{ $credito->empresa->ruc }} @endif
        </div>
    </div>
</body>
</html>
