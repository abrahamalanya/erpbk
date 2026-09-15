<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acta de Recepción de Vehículos #{{ $credito->id }}</title>
    <style>
        @page { margin: 4cm 1cm 3cm 3cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.3; }
        h1 { font-size: 13px; text-align: center; margin: 0; }
        h2 { font-size: 9.8px; margin-top: 8px; margin-bottom: 3px; }
        p { text-align: justify; margin: 4px 0; }
        .numero { display: inline-block; border: 1px solid #000; padding: 3px 8px; font-weight: bold; font-size: 10px; }
        table.veh { width: 100%; border-collapse: collapse; margin: 5px 0; }
        table.veh th, table.veh td { border: 1px solid #999; padding: 3px 5px; font-size: 8.7px; vertical-align: top; }
        table.veh th { background: #eee; text-align: left; width: 26%; }
        table.firmas { width: 100%; margin-top: 26px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: bottom; }
        .firma-espacio { height: 42px; }
        .firma-linea { margin-top: 6px; border-top: 1px solid #000; padding-top: 3px; }
        .check { font-weight: bold; }
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
            <td style="border: none; width: 100px; vertical-align: middle;"></td>
            <td style="border: none; text-align: center; vertical-align: middle;">
                <h1>ACTA DE RECEPCI&Oacute;N DE VEH&Iacute;CULOS EN GARANT&Iacute;A</h1>
            </td>
            <td style="border: none; width: 90px; text-align: right; vertical-align: middle;">
                <span class="numero">N&deg; {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}</span>
            </td>
        </tr>
    </table>

    <p>
        En {{ $ciudad }}, a {{ $fecha }}, el(la) se&ntilde;or(a) <strong>{{ $clienteNombre }}</strong>, con
        {{ $tipoDoc }} N&deg; {{ $credito->cliente->numero_documento }}, en adelante EL CLIENTE, hace entrega a
        <strong>{{ strtoupper($empresaNombre) }}</strong> del(los) veh&iacute;culo(s) que se detalla(n) a
        continuaci&oacute;n, en garant&iacute;a del cr&eacute;dito vehicular N&deg;
        {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }} por S/ {{ number_format($credito->monto_prestamo, 2) }}.
    </p>

    @foreach ($garantias as $i => $v)
        <h2>Veh&iacute;culo {{ $i + 1 }} &mdash; {{ $v->codigo ?? '' }}</h2>
        <table class="veh">
            <tr><th>Placa</th><td>{{ strtoupper($v->placa ?? '-') }}</td><th>Clase</th><td>{{ strtoupper($v->clase ?? '-') }}</td></tr>
            <tr><th>Marca</th><td>{{ strtoupper($v->marca ?? '-') }}</td><th>Modelo</th><td>{{ strtoupper($v->modelo ?? '-') }}</td></tr>
            <tr><th>A&ntilde;o</th><td>{{ $v->anio ?? '-' }}</td><th>Color</th><td>{{ strtoupper($v->color ?? '-') }}</td></tr>
            <tr><th>N&deg; Motor</th><td>{{ strtoupper($v->motor ?? '-') }}</td><th>N&deg; Serie</th><td>{{ strtoupper($v->serie ?? '-') }}</td></tr>
            <tr><th>Propietario</th><td colspan="3">{{ strtoupper($v->propietario ?? $clienteNombre) }}</td></tr>
            <tr>
                <th>Entrega</th>
                <td colspan="3">
                    SOAT: <span class="check">{{ $v->tiene_soat ? 'S&Iacute;' : 'NO' }}</span> &nbsp;&middot;&nbsp;
                    Llave: <span class="check">{{ $v->dejo_llave ? 'S&Iacute;' : 'NO' }}</span> &nbsp;&middot;&nbsp;
                    Tarjeta de propiedad: <span class="check">{{ $v->dejo_tarjeta_propiedad ? 'S&Iacute;' : 'NO' }}</span>
                </td>
            </tr>
            <tr><th>Valorizaci&oacute;n</th><td>S/ {{ number_format($v->valorizacion, 2) }}</td><th>Observaci&oacute;n</th><td>{{ $v->observacion ? strtoupper($v->observacion) : '-' }}</td></tr>
        </table>
    @endforeach

    <p>
        EL CLIENTE declara que el(los) veh&iacute;culo(s) se entrega(n) en el estado descrito y autoriza su custodia por
        parte de {{ strtoupper($empresaNombre) }} mientras el cr&eacute;dito se encuentre vigente. Ambas partes suscriben
        la presente acta en se&ntilde;al de conformidad.
    </p>

    <table class="firmas">
        <tr>
            <td>
                <div class="firma-espacio"></div>
                <div class="firma-linea">{{ $clienteNombre }}<br>{{ $tipoDoc }} N&deg;: {{ $credito->cliente->numero_documento }}<br><small>Entrega conforme</small></div>
            </td>
            <td>
                <div class="firma-espacio"></div>
                <div class="firma-linea">{{ strtoupper($empresaNombre) }}<br>@if ($credito->empresa->ruc) RUC: {{ $credito->empresa->ruc }}<br>@endif<small>Recibe conforme</small></div>
            </td>
        </tr>
    </table>
</body>
</html>
