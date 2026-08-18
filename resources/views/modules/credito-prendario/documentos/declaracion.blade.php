<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Declaración Jurada #{{ $credito->id }}</title>
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
    <h1>DECLARACIÓN JURADA DE PROCEDENCIA DEL BIEN</h1>
    <p style="text-align: center;">{{ $credito->empresa->nombre }} — {{ $credito->agencia->nombre }}</p>

    <table>
        <tr><td class="label">Cliente</td><td>{{ $credito->cliente->nombre }} {{ $credito->cliente->apellido }}</td></tr>
        <tr><td class="label">Documento</td><td>{{ strtoupper($credito->cliente->tipo_documento) }} {{ $credito->cliente->numero_documento }}</td></tr>
        <tr><td class="label">Bien declarado</td><td>{{ $credito->bien->nombre }} ({{ ucfirst($credito->bien->tipo) }})</td></tr>
        <tr><td class="label">Crédito asociado</td><td>#{{ $credito->id }}</td></tr>
    </table>

    <p class="placeholder">
        [[ CONTENIDO DE RELLENO — reemplazar con el texto real de la declaración jurada: que el cliente
        declara ser propietario legítimo del bien entregado en garantía, que no tiene gravámenes previos,
        procedencia lícita, y las consecuencias legales de una declaración falsa. Debe ser redactado/revisado
        por el usuario o su abogado antes de usarse en producción. ]]
    </p>

    <table style="margin-top: 60px;">
        <tr>
            <td style="width: 50%;"><div class="firma-linea">Firma del cliente</div></td>
        </tr>
    </table>
</body>
</html>
