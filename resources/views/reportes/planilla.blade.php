<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        @page { margin: 1cm; size: landscape; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1a1a1a; }
        .cabecera { width: 100%; margin-bottom: 10px; }
        .cabecera td { padding: 1px 0; vertical-align: top; }
        .titulo { font-size: 11px; font-weight: bold; }
        .emision { text-align: right; color: #444; }
        .datos td { font-size: 9px; }

        table.planilla { width: 100%; border-collapse: collapse; }
        table.planilla th, table.planilla td { border: 1px solid #555; padding: 3px; }
        table.planilla th { background: #e8e8e8; font-size: 7.5px; text-align: center; }
        td.num { text-align: right; }
        td.centro { text-align: center; }
        tfoot td { font-weight: bold; background: #f2f2f2; }
        .vacio { text-align: center; color: #888; font-style: italic; }
    </style>
</head>
<body>
    <table class="cabecera">
        <tr>
            <td class="titulo">{{ $titulo }}</td>
            <td class="emision">{{ now()->format('d/m/Y') }}<br>{{ now()->format('H:i:s') }}</td>
        </tr>
    </table>

    <table class="cabecera datos">
        <tr><td>RUC : {{ $ruc ?? '—' }}</td></tr>
        <tr><td>Empleador : {{ mb_strtoupper($empleador ?? '—') }}</td></tr>
        <tr><td>Periodo : {{ $periodo }}</td></tr>
        <tr><td>Agencia : {{ $agencia }} &nbsp;|&nbsp; Fecha de pago : {{ \Illuminate\Support\Carbon::parse($fecha_pago)->format('d/m/Y') }}</td></tr>
    </table>

    <table class="planilla">
        <thead>
            <tr>
                <th colspan="5">Datos del Trabajador</th>
                <th colspan="6">Informaci&oacute;n declarada</th>
            </tr>
            <tr>
                <th colspan="2">Documento de Identidad</th>
                <th rowspan="2">Apellidos</th>
                <th rowspan="2">Nombres</th>
                <th rowspan="2">Sit.</th>
                <th colspan="2">Ingresos</th>
                <th rowspan="2">Descuentos</th>
                <th rowspan="2">Tributos y aportes del Trabajador</th>
                <th rowspan="2">Neto a pagar</th>
                <th rowspan="2">Tributos y Aportes del Empleador</th>
            </tr>
            <tr>
                <th>Tipo</th>
                <th>N&uacute;mero</th>
                <th>Devengado</th>
                <th>Pagado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    @foreach ($fila as $indice => $valor)
                        {{-- Las columnas de la 6ta en adelante son montos. --}}
                        <td @class(['num' => $indice >= 5, 'centro' => $indice === 0])>{{ $valor }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="11" class="vacio">Sin trabajadores</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">TOTALES</td>
                @foreach ($totales as $monto)
                    <td class="num">{{ number_format((float) $monto, 2) }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>
</body>
</html>
