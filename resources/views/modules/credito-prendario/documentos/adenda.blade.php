<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Adenda #{{ $credito->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td { padding: 3px 4px; vertical-align: top; }
        td.label { width: 35%; font-weight: bold; }
        .placeholder { color: #b00; font-style: italic; }
        .firma-linea { margin-top: 60px; border-top: 1px solid #000; width: 250px; text-align: center; padding-top: 4px; }
    </style>
</head>
<body>
    <h1>ADENDA AL CONTRATO DE PRÉSTAMO PRENDARIO</h1>
    <p style="text-align: center;">{{ $credito->empresa->nombre }} — {{ $credito->agencia->nombre }}</p>

    <p>
        Esta adenda modifica y extiende el contrato original del crédito #{{ $credito->refrendo_de_credito_id }},
        mediante refrendo N.° {{ $credito->numero_refrendo }}, conservando los mismos bienes en garantía.
    </p>

    <table>
        <tr><td class="label">Cliente</td><td>{{ $credito->cliente->nombre }} {{ $credito->cliente->apellido }}</td></tr>
        <tr><td class="label">Bienes en garantía</td><td>{{ $credito->bienes->map(fn ($bien) => "{$bien->nombre} (".ucfirst($bien->tipo).')')->implode(', ') }}</td></tr>
        <tr><td class="label">Nuevo crédito</td><td>#{{ $credito->id }}</td></tr>
        <tr><td class="label">Monto (capital)</td><td>{{ number_format($credito->monto_prestamo, 2) }}</td></tr>
        <tr><td class="label">Interés</td><td>{{ number_format($credito->interes, 2) }}%</td></tr>
        <tr><td class="label">Nuevo plazo</td><td>{{ $credito->plazo_dias }} días</td></tr>
        <tr><td class="label">Nueva fecha de vencimiento</td><td>{{ optional($credito->fecha_vencimiento)->format('d/m/Y') }}</td></tr>
    </table>

    <p class="placeholder">
        [[ CONTENIDO DE RELLENO — reemplazar con el texto legal real de la adenda: confirmación de que el
        interés vencido fue cancelado, que el capital y la garantía se mantienen sin cambios, la nueva
        fecha de vencimiento, y la aceptación mutua de estos nuevos términos. Debe ser redactado/revisado
        por el usuario o su abogado antes de usarse en producción. ]]
    </p>

    <table style="margin-top: 60px;">
        <tr>
            <td style="width: 50%;"><div class="firma-linea">Firma del cliente</div></td>
            <td style="width: 50%;"><div class="firma-linea">Firma por {{ $credito->empresa->nombre }}</div></td>
        </tr>
    </table>
</body>
</html>
