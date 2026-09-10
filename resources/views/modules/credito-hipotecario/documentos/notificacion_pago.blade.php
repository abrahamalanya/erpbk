<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Notificación — Requerimiento de pago — Crédito #{{ $credito->id }}</title>
    <style>
        @page { margin: 2.2cm 2cm 1.8cm 2cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; line-height: 1.4; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 12px; letter-spacing: 2px; }
        p { text-align: justify; margin: 5px 0; }
        .ref td { padding: 1px 0; vertical-align: top; }
        .ref td.k { width: 90px; font-weight: bold; }
        hr { border: none; border-top: 1px solid #000; margin: 10px 0; }
        table.cuotas { width: 70%; border-collapse: collapse; margin: 6px 0; }
        table.cuotas td, table.cuotas th { border: 1px solid #666; padding: 2px 8px; }
        table.cuotas th { background: #eee; }
        .num { text-align: right; }
        .firma { margin-top: 34px; }
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

        $hoy = now()->startOfDay();
        $vencidas = ($credito->cuotas ?? collect())
            ->filter(fn ($q) => $q->fecha_vencimiento && $q->fecha_vencimiento->copy()->startOfDay()->lt($hoy))
            ->sortBy('numero_cuota')->values();
        $deuda = $vencidas->reduce(fn ($carry, $q) => bcadd($carry, (string) $q->monto_total, 2), '0');

        $domicilio = trim(implode(', ', array_filter([$c->direccion, $c->distrito, $c->provincia, $c->departamento])));
    @endphp

    <h1>NOTIFICACI&Oacute;N</h1>

    <p style="text-align: right; margin-bottom: 14px;">{{ $ciudad }}, {{ $fecha }}</p>

    <table class="ref">
        <tr><td class="k">Señor:</td><td>{{ mb_strtoupper($c->nombre.' '.$c->apellido) }}</td></tr>
        <tr><td class="k">Dirección:</td><td>{{ mb_strtoupper($domicilio ?: '—') }}</td></tr>
        <tr><td class="k">Presente.-</td><td></td></tr>
        <tr><td class="k">Asunto:</td><td><strong>REQUERIMIENTO DE PAGO</strong></td></tr>
        <tr><td class="k">Referencia:</td><td>CONSTITUCIÓN DE GARANTÍA HIPOTECARIA</td></tr>
    </table>
    <hr>

    <p>De nuestra consideración:</p>

    <p>
        Sirva la presente, que será entregada a Ud., para requerirle cordialmente que cumpla dentro del
        plazo de <strong>72 HORAS</strong> con la obligación de pago correspondiente a la
        <strong>CONSTITUCIÓN DE GARANTÍA HIPOTECARIA</strong>; obligación ascendente a la suma de
        <strong>S/ {{ number_format((float) $deuda, 2) }} ({{ NumeroALetras::soles($deuda) }})</strong>,
        que surge por el incumplimiento de pago de <strong>{{ $vencidas->count() }} CUOTA(S)</strong>,
        deuda de dinero del desembolso de
        S/ {{ number_format((float) $credito->monto_prestamo, 2) }}
        ({{ NumeroALetras::soles($credito->monto_prestamo) }}). De la cual se alcanza copia de la
        Constitución de Garantía Hipotecaria.
    </p>

    <p>
        Este requerimiento se realiza en atención a que, hasta la fecha,
        @if ($vencidas->isEmpty())
            no se registran cuotas vencidas pendientes de pago.
        @else
            son <strong>{{ $vencidas->count() }}</strong> las cuotas vencidas:
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
        Viéndonos en la imperiosa necesidad de recurrir a este medio formal para requerir lo adeudado,
        le recordamos que, de persistir en su incumplimiento, iniciaremos las acciones legales
        necesarias para recuperar lo adeudado más los intereses de ley y gastos administrativos que a la
        fecha se hubieran generado.
    </p>

    <p>
        Lo instamos a comunicarse con nosotros a la brevedad posible
        @if (filled($emp->celular_cobranzas))
            al número de celular N° {{ $emp->celular_cobranzas }}@if (filled($emp->apoderado_legal)) ({{ mb_strtoupper($emp->apoderado_legal) }})@endif.
        @else
            al número de celular N° __________________________.
        @endif
    </p>

    <p style="margin-top: 14px;"><strong>ADJUNTO:</strong></p>
    <p style="margin: 2px 0;">1-A&nbsp;&nbsp;Copia de la Constitución de Garantía Hipotecaria.</p>
    <p style="margin: 2px 0;">1-B&nbsp;&nbsp;Copia del contrato de préstamo.</p>

    <div class="firma">
        <p style="margin: 0;">Atentamente,</p>
        <p style="margin: 30px 0 0;"><strong>{{ mb_strtoupper($razonSocial) }}</strong></p>
        @if (filled($emp->ruc))<p style="margin: 0;">RUC N.º {{ $emp->ruc }}</p>@endif
    </div>
</body>
</html>
