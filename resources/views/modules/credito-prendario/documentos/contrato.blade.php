<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato de Préstamo Prendario #{{ $credito->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 4px; }
        h2 { font-size: 13px; margin-top: 18px; margin-bottom: 6px; border-bottom: 1px solid #999; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        td { padding: 3px 4px; vertical-align: top; }
        td.label { width: 35%; font-weight: bold; }
        .placeholder { color: #b00; font-style: italic; }
        .firma-linea { margin-top: 60px; border-top: 1px solid #000; width: 250px; text-align: center; padding-top: 4px; }
    </style>
</head>
<body>
    <h1>CONTRATO DE PRÉSTAMO CON GARANTÍA PRENDARIA</h1>
    <p style="text-align: center;">{{ $credito->empresa->nombre }} — {{ $credito->agencia->nombre }}</p>

    <h2>Datos del cliente</h2>
    <table>
        <tr><td class="label">Nombre completo</td><td>{{ $credito->cliente->nombre }} {{ $credito->cliente->apellido }}</td></tr>
        <tr><td class="label">Documento</td><td>{{ strtoupper($credito->cliente->tipo_documento) }} {{ $credito->cliente->numero_documento }}</td></tr>
        <tr><td class="label">Dirección</td><td>{{ $credito->cliente->direccion }}</td></tr>
        <tr><td class="label">Teléfono</td><td>{{ $credito->cliente->telefono }}</td></tr>
    </table>

    <h2>Bien en garantía</h2>
    <table>
        <tr><td class="label">Tipo</td><td>{{ ucfirst($credito->bien->tipo) }}</td></tr>
        <tr><td class="label">Nombre</td><td>{{ $credito->bien->nombre }}</td></tr>
        <tr><td class="label">Marca / Modelo</td><td>{{ $credito->bien->marca }} / {{ $credito->bien->modelo }}</td></tr>
        <tr><td class="label">Serie</td><td>{{ $credito->bien->serie }}</td></tr>
        <tr><td class="label">Cantidad</td><td>{{ $credito->bien->cantidad }}</td></tr>
        <tr><td class="label">Valorización</td><td>{{ number_format($credito->bien->valorizacion, 2) }}</td></tr>
    </table>

    <h2>Condiciones del préstamo</h2>
    <table>
        <tr><td class="label">Monto prestado</td><td>{{ number_format($credito->monto_prestamo, 2) }}</td></tr>
        <tr><td class="label">Interés</td><td>{{ number_format($credito->interes, 2) }}%</td></tr>
        <tr><td class="label">Tipo de cuota</td><td>{{ ucfirst($credito->tipo_cuota) }}</td></tr>
        <tr><td class="label">Plazo</td><td>{{ $credito->plazo_dias }} días</td></tr>
        <tr><td class="label">Fecha de desembolso</td><td>{{ optional($credito->fecha_desembolso)->format('d/m/Y') }}</td></tr>
        <tr><td class="label">Fecha de vencimiento</td><td>{{ optional($credito->fecha_vencimiento)->format('d/m/Y') }}</td></tr>
    </table>

    <h2>Cláusulas</h2>
    <p class="placeholder">
        [[ CONTENIDO DE RELLENO — reemplazar con las cláusulas legales reales del contrato de préstamo
        prendario antes de usar en producción: obligaciones del cliente, condiciones de refrendo,
        tratamiento de mora, condiciones de venta del bien tras el plazo de gracia, jurisdicción, etc. ]]
    </p>

    <table style="margin-top: 60px;">
        <tr>
            <td style="width: 50%;"><div class="firma-linea">Firma del cliente</div></td>
            <td style="width: 50%;"><div class="firma-linea">Firma por {{ $credito->empresa->nombre }}</div></td>
        </tr>
    </table>
</body>
</html>
