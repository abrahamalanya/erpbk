<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $comprobante->tipo_documento->denominacion() }} {{ $comprobante->numeroFormateado() }}</title>
    <style>
        @page { margin: 1.6cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.35; }
        .emisor { text-align: center; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; margin-bottom: 12px; }
        .emisor h1 { font-size: 15px; margin: 0 0 2px; }
        .emisor .razon { font-size: 11px; font-weight: bold; }
        .emisor .direccion { font-size: 9px; color: #444; }
        .documento { text-align: center; margin: 10px 0 14px; }
        .documento h2 { font-size: 13px; margin: 0; letter-spacing: 0.5px; }
        .documento .fecha { font-size: 9px; color: #444; margin-top: 2px; }
        table.datos { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.datos td { padding: 3px 4px; border-bottom: 1px solid #ddd; vertical-align: top; }
        table.datos td.label { width: 22%; color: #555; }
        table.detalle { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.detalle th, table.detalle td { border: 1px solid #bbb; padding: 4px 5px; text-align: left; }
        table.detalle th { background: #f2f2f2; font-size: 9px; }
        table.detalle td.num, table.detalle th.num { text-align: right; }
        .totales { width: 45%; margin-left: auto; margin-top: 10px; border-collapse: collapse; }
        .totales td { padding: 3px 5px; }
        .totales td.label { color: #555; }
        .totales td.num { text-align: right; }
        .totales tr.grande td { border-top: 1px solid #1a1a1a; font-weight: bold; font-size: 11px; }
        .letras { margin-top: 12px; font-size: 9px; font-style: italic; }
        .pie { margin-top: 26px; border-top: 1px solid #ccc; padding-top: 6px; font-size: 8px; color: #666; text-align: center; }
        .seguridad { margin-top: 8px; text-align: center; font-size: 10px; }
        .seguridad .codigo { font-family: monospace; font-size: 13px; font-weight: bold; letter-spacing: 2px; }
        .estado { display: inline-block; margin-top: 10px; padding: 3px 10px; border: 1px solid #999; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #555; }
    </style>
</head>
<body>
    @php
        $tipo = $comprobante->tipo_documento;
        $fecha = \Carbon\Carbon::parse($comprobante->fecha_emision)->locale('es')->translatedFormat('d \d\e F \d\e\l Y');
        $emisor = $comprobante;
    @endphp

    <div class="emisor">
        <div class="razon">{{ $emisor->emisor_razon_social }}</div>
        <div class="direccion">
            {{ $emisor->emisor_direccion_completa ?? 'Sin domicilio fiscal registrado' }}
            @if($emisor->emisor_ubigeo) &mdash; Ubigeo {{ $emisor->emisor_ubigeo }} @endif
        </div>
        <div class="direccion">RUC {{ $emisor->emisor_ruc }}</div>
    </div>

    <div class="documento">
        <h2>{{ $tipo->denominacion() }}</h2>
        <div class="fecha">{{ $comprobante->numeroFormateado() }} &mdash; {{ $fecha }}</div>
    </div>

    <table class="datos">
        <tr>
            <td class="label">Se&ntilde;or(es)</td>
            <td>{{ $comprobante->receptor_nombre }}</td>
        </tr>
        <tr>
            <td class="label">Documento</td>
            <td>{{ strtoupper($comprobante->receptor_tipo_documento) }} {{ $comprobante->receptor_numero_documento }}</td>
        </tr>
        @if($comprobante->receptor_domicilio)
            <tr>
                <td class="label">Direcci&oacute;n</td>
                <td>{{ $comprobante->receptor_domicilio }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Moneda</td>
            <td>{{ $comprobante->moneda === 'PEN' ? 'Soles' : $comprobante->moneda }}</td>
        </tr>
        <tr>
            <td class="label">Forma de pago</td>
            <td>{{ $comprobante->forma_pago === '01' ? 'Contado' : 'Cr&eacute;dito' }}</td>
        </tr>
    </table>

    <table class="detalle">
        <thead>
            <tr>
                <th style="width:6%;">N.°</th>
                <th style="width:12%;">C&oacute;digo</th>
                <th>Descripci&oacute;n</th>
                <th class="num" style="width:10%;">Cant.</th>
                <th class="num" style="width:14%;">Valor unitario</th>
                <th class="num" style="width:14%;">IGV</th>
                <th class="num" style="width:14%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($comprobante->detalles as $detalle)
                <tr>
                    <td>{{ $detalle->orden }}</td>
                    <td>{{ $detalle->codigo_producto ?? 'SIN-CODIGO' }}</td>
                    <td>{{ $detalle->descripcion }}</td>
                    <td class="num">{{ number_format((float) $detalle->cantidad, 2) }}</td>
                    <td class="num">{{ number_format((float) $detalle->valor_unitario, 2) }}</td>
                    <td class="num">{{ number_format((float) $detalle->igv, 2) }}</td>
                    <td class="num">{{ number_format((float) $detalle->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr>
            <td class="label">Subtotal</td>
            <td class="num">S/ {{ number_format((float) $comprobante->subtotal, 2) }}</td>
        </tr>
        @if((float) $comprobante->descuento_global > 0)
            <tr>
                <td class="label">Descuento</td>
                <td class="num">S/ {{ number_format((float) $comprobante->descuento_global, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">IGV ({{ number_format((float) $comprobante->porcentaje_igv, 2) }}%)</td>
            <td class="num">S/ {{ number_format((float) $comprobante->igv, 2) }}</td>
        </tr>
        <tr class="grande">
            <td class="label">Total</td>
            <td class="num">S/ {{ number_format((float) $comprobante->total, 2) }}</td>
        </tr>
    </table>

    <p class="letras">Son: {{ $comprobante->importe_letras }}</p>

    @if($comprobante->codigo_seguridad)
        <div class="seguridad">
            <div>C&oacute;digo de seguridad</div>
            <div class="codigo">*{{ $comprobante->codigo_seguridad }}*</div>
        </div>
    @endif

    <div style="text-align:center;">
        <span class="estado">{{ $comprobante->estado->value }}</span>
    </div>

    <div class="pie">
        Representaci&oacute;n impresa de un comprobante electr&oacute;nico &mdash;
        {{ $comprobante->numeroFormateado() }} &mdash;
        Emitido el {{ $fecha }}
    </div>
</body>
</html>