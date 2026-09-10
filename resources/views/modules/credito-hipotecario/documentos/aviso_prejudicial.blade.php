<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Carta de aviso prejudicial — Crédito #{{ $credito->id }}</title>
    <style>
        @page { margin: 3cm 2.2cm 2.5cm 2.2cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 18px; letter-spacing: 2px; }
        p { text-align: justify; margin: 7px 0; }
        .ref td { padding: 1px 0; vertical-align: top; }
        .ref td.k { width: 90px; font-weight: bold; }
        table.cuotas { width: 70%; border-collapse: collapse; margin: 8px 0; }
        table.cuotas td, table.cuotas th { border: 1px solid #666; padding: 3px 8px; }
        table.cuotas th { background: #eee; }
        .num { text-align: right; }
        .firma { margin-top: 55px; text-align: center; width: 60%; }
        .firma-linea { border-top: 1px solid #000; padding-top: 3px; margin-top: 42px; }
    </style>
</head>
<body>
    @php
        use Illuminate\Support\Str;
        use App\Nucleo\Support\NumeroALetras;

        $c = $credito->cliente;
        $emp = $credito->empresa;
        $fecha = $documento->generado_at->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y');
        $ciudad = Str::after($credito->agencia->nombre, 'Agencia ');
        $razonSocial = $emp->razon_social ?: $emp->nombre;
        $tipoDoc = mb_strtoupper($c->tipo_documento);

        $hoy = now()->startOfDay();
        $vencidas = ($credito->cuotas ?? collect())
            ->filter(fn ($q) => $q->fecha_vencimiento && $q->fecha_vencimiento->copy()->startOfDay()->lt($hoy))
            ->sortBy('numero_cuota')->values();
        $deuda = $vencidas->reduce(fn ($carry, $q) => bcadd($carry, (string) $q->monto_total, 2), '0');

        $domicilio = trim(implode(', ', array_filter([$c->direccion, $c->distrito, $c->provincia, $c->departamento])));
    @endphp

    <h1>CARTA DE AVISO PREJUDICIAL</h1>

    <p style="text-align: right; margin-bottom: 14px;">{{ mb_strtoupper($ciudad) }}, {{ mb_strtoupper($fecha) }}</p>

    <table class="ref">
        <tr><td class="k">Señor:</td><td>{{ mb_strtoupper($c->nombre.' '.$c->apellido) }}</td></tr>
        <tr><td class="k">{{ $tipoDoc }} N.º</td><td>{{ $c->numero_documento }}</td></tr>
        <tr><td class="k">Domicilio:</td><td>{{ mb_strtoupper($domicilio ?: '—') }}</td></tr>
    </table>

    <p style="margin-top: 12px;">De mi mayor consideración:</p>

    <p>
        Sirva la presente, que será entregada a Ud., para requerirle cordialmente que cumpla dentro del
        plazo de <strong>72 HORAS</strong> con la obligación de pago correspondiente a la
        <strong>CONSTITUCIÓN DE GARANTÍA HIPOTECARIA</strong>; obligación ascendente a la suma de
        <strong>S/ {{ number_format((float) $deuda, 2) }} ({{ NumeroALetras::soles($deuda) }})</strong>,
        que surge por el incumplimiento de pago de sus
        <strong>{{ $vencidas->count() }} CUOTA(S)</strong>, deuda de dinero del desembolso de
        S/ {{ number_format((float) $credito->monto_prestamo, 2) }}
        ({{ NumeroALetras::soles($credito->monto_prestamo) }}). De la cual se alcanza copia de la
        Constitución de Garantía Hipotecaria.
    </p>

    <p>
        Este requerimiento se realiza en atención a que, hasta la fecha, no se ha efectuado la
        cancelación de
        @if ($vencidas->isEmpty())
            las cuotas vencidas.
        @else
            sus <strong>{{ $vencidas->count() }}</strong> cuotas vencidas:
        @endif
    </p>

    @if ($vencidas->isNotEmpty())
        <table class="cuotas">
            <tr><th>Cuota</th><th>Vencimiento</th><th class="num">Monto (S/)</th></tr>
            @foreach ($vencidas as $q)
                <tr>
                    <td>{{ $q->numero_cuota }}</td>
                    <td>{{ $q->fecha_vencimiento->format('d/m/Y') }}</td>
                    <td class="num">{{ number_format((float) $q->monto_total, 2) }}</td>
                </tr>
            @endforeach
            <tr><td colspan="2"><strong>Total</strong></td><td class="num"><strong>{{ number_format((float) $deuda, 2) }}</strong></td></tr>
        </table>
    @endif

    <p>
        Por lo expuesto, se le intima formalmente a que, en el plazo improrrogable de <strong>cinco (05)
        días hábiles</strong> de recibida la presente, cumpla con el pago íntegro de la suma adeudada.
        En caso contrario, se procederá sin más trámite a la ejecución de la garantía hipotecaria
        constituida a favor de <strong>{{ mb_strtoupper($razonSocial) }}</strong>, conforme a ley, con el
        cobro de intereses moratorios, costas y costos del proceso.
    </p>

    <p>Sin otro particular, quedo de usted.</p>
    <p>Atentamente,</p>

    <div class="firma">
        <div class="firma-linea">
            <strong>{{ mb_strtoupper(filled($emp->apoderado_legal) ? $emp->apoderado_legal : $emp->representante_legal) }}</strong><br>
            Apoderado Legal<br>
            {{ mb_strtoupper($razonSocial) }}@if (filled($emp->ruc))<br>RUC N.º {{ $emp->ruc }}@endif
        </div>
    </div>
</body>
</html>
