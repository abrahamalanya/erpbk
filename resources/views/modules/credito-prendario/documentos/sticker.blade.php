<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Etiquetas de producto — Crédito #{{ $credito->id }}</title>
    <style>
        @page { size: 100mm 60mm; margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; margin: 0; }
        .label { width: 100mm; height: 60mm; padding: 4mm 5mm; page-break-after: always; }
        .label:last-child { page-break-after: auto; }
        .empresa { font-size: 7.5px; text-transform: uppercase; color: #555; letter-spacing: .4px; }
        .producto { font-size: 15px; font-weight: bold; text-transform: uppercase; margin: 1.5mm 0 0.5mm; }
        .cliente { font-size: 10px; text-transform: uppercase; margin-bottom: 1.5mm; }
        table.fila { width: 100%; border-collapse: collapse; }
        td.datos-col { vertical-align: middle; padding: 0 3mm 0 0; }
        td.codigo-col { vertical-align: middle; width: 33mm; padding: 0; }
        table.datos { width: 100%; border-collapse: collapse; font-size: 8.5px; }
        table.datos td { padding: 0.6px 0; }
        table.datos td.k { color: #555; width: 52%; }
        .codigo-box { border: 2px solid #000; padding: 1.5mm 1mm; text-align: center; }
        .codigo-box .cap { font-size: 6px; text-transform: uppercase; color: #555; letter-spacing: .5px; }
        .codigo-box .cod { font-size: 15px; font-weight: bold; letter-spacing: 1.5px; }
    </style>
</head>
<body>
    @php
        $desembolsado = ! empty($credito->fecha_desembolso);
        $clienteNombre = strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido);
    @endphp

    @foreach ($garantias as $g)
        <div class="label">
            <div class="empresa">{{ $credito->empresa->nombre }} &middot; {{ $credito->agencia->nombre }} &middot; Cr&eacute;dito #{{ $credito->id }}</div>
            <div class="producto">{{ $g->nombre }}</div>
            <div class="cliente">{{ $clienteNombre }}</div>
            <table class="fila">
                <tr>
                    <td class="datos-col">
                        <table class="datos">
                            <tr>
                                <td class="k">Monto desembolsado</td>
                                <td>{{ $desembolsado ? 'S/ '.number_format((float) $credito->monto_prestamo, 2) : '—' }}</td>
                            </tr>
                            <tr>
                                <td class="k">Fecha de ingreso</td>
                                <td>{{ optional($g->created_at)->format('d/m/Y') ?? '—' }}</td>
                            </tr>
                            <tr>
                                <td class="k">Fecha de vencimiento</td>
                                <td>{{ $credito->fecha_vencimiento ? \Illuminate\Support\Carbon::parse($credito->fecha_vencimiento)->format('d/m/Y') : '—' }}</td>
                            </tr>
                        </table>
                    </td>
                    <td class="codigo-col">
                        <div class="codigo-box">
                            <div class="cap">C&oacute;digo del producto</div>
                            <div class="cod">{{ $g->codigo }}</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    @endforeach
</body>
</html>
