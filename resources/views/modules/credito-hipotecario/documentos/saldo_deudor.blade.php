<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Liquidaci&oacute;n de saldo deudor &mdash; Cr&eacute;dito #{{ $credito->id }}</title>
    <style>
        @page { margin: 2.5cm 2.5cm 2.5cm 3cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; line-height: 1.5; }
        h1 { font-size: 13px; text-align: center; text-decoration: underline; margin: 0 0 20px; }
        h2 { font-size: 11.5px; margin: 18px 0 8px; }
        p { text-align: justify; margin: 7px 0; }
        ul { margin: 6px 0; padding-left: 22px; }
        li { margin: 4px 0; text-align: justify; }
        .total { font-weight: bold; }
        hr { border: 0; border-top: 1px solid #999; margin: 16px 0; }
        .firma { margin-top: 40px; }
        .firma-imagen { max-height: 90px; max-width: 220px; margin-bottom: 4px; }
        .aviso { border: 1px solid #999; background: #f3f3f3; padding: 6px 10px; text-align: center; font-weight: bold; }
    </style>
</head>
<body>
    @php
        use App\Nucleo\Support\NumeroALetras;

        $l = $liquidacion;
        $emp = $credito->empresa;
        $c = $credito->cliente;
        $razonSocial = $emp->razon_social ?: $emp->nombre;
        $fecha = fn (?string $f): string => $f ? \Illuminate\Support\Carbon::parse($f)->locale('es')->translatedFormat('d \d\e F \d\e Y') : '—';
        $corta = fn (string $f): string => \Illuminate\Support\Carbon::parse($f)->format('d/m/Y');
        $soles = fn (string $monto): string => 'S/ '.number_format((float) $monto, 2);
        $periodos = ['diario' => 'días', 'semanal' => 'semanas', 'quincenal' => 'quincenas', 'mensual' => 'meses'];
        $saldoAFavor = bccomp($l['saldo'], '0', 2) < 0;
        $capitalAnterior = $l['capital_inicial'];
        $numeroPago = 0;
    @endphp

    <h1>ESTADO DE CUENTA, LIQUIDACI&Oacute;N Y DETERMINACI&Oacute;N DEL SALDO DEUDOR</h1>

    @if ($l['proyectado'])
        <p class="aviso">
            PROYECCI&Oacute;N &mdash; El cr&eacute;dito a&uacute;n no ha sido desembolsado: no se han devengado intereses
            ni registrado pagos. La liquidaci&oacute;n real se calcula desde la fecha de desembolso.
        </p>
    @endif

    <ul>
        <li><strong>Fecha de emisi&oacute;n de liquidaci&oacute;n:</strong> {{ $fecha($l['fecha_corte']) }}</li>
        <li><strong>Deudor:</strong> {{ mb_strtoupper($c->apellido.' '.$c->nombre) }} ({{ mb_strtoupper($c->tipo_documento) }} N.&ordm; {{ $c->numero_documento }})</li>
        <li><strong>Acreedor / Emisor:</strong> {{ mb_strtoupper($razonSocial) }}</li>
        <li><strong>Fecha de Origen de Deuda:</strong> {{ $l['proyectado'] ? 'Pendiente de desembolso' : $fecha($l['fecha_origen']) }}</li>
        <li><strong>Monto del Capital Original:</strong> {{ $soles($l['capital_inicial']) }} ({{ NumeroALetras::soles($l['capital_inicial']) }})</li>
        <li>
            <strong>Plazo Original Pactado:</strong>
            {{ $l['numero_cuotas'] }} {{ $periodos[$l['tipo_cuota']] ?? 'cuotas' }}@if ($l['vencimiento_programado']) (Vencimiento ordinario programado: {{ $corta($l['vencimiento_programado']) }})@endif
        </li>
        <li><strong>Condici&oacute;n de Liquidaci&oacute;n:</strong> Reducci&oacute;n de Tasas por Mandato Legal (Topes BCRP / Art. 1246 C&oacute;digo Civil)</li>
    </ul>

    <p>
        Por medio de la presente liquidaci&oacute;n t&eacute;cnica se procede a determinar el estado financiero exigible de
        la obligaci&oacute;n dineraria contra&iacute;da en la fecha indicada, conforme a las condiciones, intereses,
        penalidades y amortizaciones registradas hist&oacute;ricamente por las partes.
    </p>

    <h2>1. RESUMEN HIST&Oacute;RICO DE CONCEPTOS LIQUIDADOS</h2>

    <ul>
        <li>Capital Inicial Hist&oacute;rico Desembolsado: {{ $soles($l['capital_inicial']) }}</li>
        <li>(+) Inter&eacute;s Compensatorio M&aacute;ximo de Ley (TEA {{ number_format((float) $l['tea_compensatoria'], 2) }}% - {{ $l['dias'] }} d&iacute;as): {{ $soles($l['interes_compensatorio']) }}</li>
        <li>(+) Inter&eacute;s Moratorio Legal Supletorio (Art. 1246 C.C. - BCRP, tasa anual {{ number_format((float) $l['tasa_moratoria'], 2) }}%): {{ $soles($l['interes_moratorio']) }}</li>
        <li>
            (&minus;) Total de Amortizaciones/Pagos Recibidos (Hist&oacute;ricos): -{{ $soles($l['total_pagos']) }}
            @if (count($l['pagos']) > 1)
                <em>({{ implode(' + ', array_map($soles, $l['pagos'])) }})</em>
            @endif
        </li>
        @if ($saldoAFavor)
            <li class="total">(=) SALDO A FAVOR DEL DEUDOR EN SOLES: {{ $soles(ltrim($l['saldo'], '-')) }}</li>
        @else
            <li class="total">(=) SALDO DEUDOR TOTAL LEGAL EXIGIBLE EN SOLES: {{ $soles($l['saldo']) }}</li>
        @endif
    </ul>

    <hr>

    <h2>2. CRONOLOG&Iacute;A DE PERIODOS Y AJUSTE DE MORA</h2>

    <ul>
        @if ($l['proyectado'])
            <li>Sin periodos devengados: el cr&eacute;dito a&uacute;n no ha sido desembolsado.</li>
        @endif
        @foreach ($l['tramos'] as $i => $t)
            @php
                $tienePago = bccomp($t['pago'], '0', 2) > 0;
                if ($tienePago) { $numeroPago++; }
            @endphp
            @if ($t['dias'] > 0 || $tienePago)
                <li>
                    Tramo {{ $i + 1 }} ({{ $corta($t['desde']) }} al {{ $corta($t['hasta']) }} - {{ $t['dias'] }} d&iacute;as transcurridos
                    {{ $tienePago ? "hasta el {$numeroPago}° pago" : 'hasta la fecha de corte' }}):
                    El capital base de {{ $soles($capitalAnterior) }} deveng&oacute; {{ $soles($t['compensatorio']) }} de inter&eacute;s
                    compensatorio y {{ $soles($t['moratorio']) }} de inter&eacute;s moratorio legal.
                    @if ($tienePago)
                        El abono de {{ $soles($t['pago']) }} se imput&oacute; {{ $soles($t['aplicado_intereses']) }} a intereses
                        @if (bccomp($t['aplicado_capital'], '0', 2) > 0)
                            y {{ $soles($t['aplicado_capital']) }} a capital, dejando un capital vivo de {{ $soles($t['capital_vivo']) }}.
                        @else
                            &mdash;se absorbi&oacute; en su totalidad por los intereses acumulados&mdash;, dejando el capital intacto.
                        @endif
                    @else
                        Sin pagos registrados en el periodo, los intereses compensatorios y moratorios contin&uacute;an
                        acumul&aacute;ndose diariamente hasta la fecha de cierre de este informe.
                    @endif
                </li>
            @endif
            @php $capitalAnterior = $t['capital_vivo']; @endphp
        @endforeach
    </ul>

    <p style="font-size: 9.5px; color: #555;">
        M&eacute;todo: inter&eacute;s diario equivalente a la tasa anual efectiva, (1 + TEA)<sup>1/360</sup> &minus; 1,
        sobre el capital vivo y sin capitalizaci&oacute;n de intereses. El moratorio corre solo sobre el capital vencido
        e impago del cronograma original desde su vencimiento. Cada pago se imputa primero a intereses y luego a
        capital (Art. 1257 C.C.).
    </p>

    <hr>

    <h2>3. DECLARACI&Oacute;N Y CERTIFICACI&Oacute;N FINAL</h2>

    <p>
        Se deja constancia de que los intereses calculados se ci&ntilde;en estrictamente a los l&iacute;mites legales
        establecidos por el Banco Central de Reserva del Per&uacute; (BCRP) para evitar la usura convencional.
        @unless ($saldoAFavor)
            De mantenerse la obligaci&oacute;n sin abonos, los intereses compensatorios y moratorios continuar&aacute;n
            acumul&aacute;ndose diariamente sobre el capital pendiente hasta la extinci&oacute;n completa de la deuda.
        @endunless
    </p>

    <div class="firma">
        @if ($emp->firma_path)
            <img class="firma-imagen" src="{{ $fotoDataUri($emp->firma_path, 400) }}"><br>
        @endif
        <strong>{{ mb_strtoupper($razonSocial) }}</strong>@if (filled($emp->ruc))<br>RUC: {{ $emp->ruc }}@endif
    </div>
    <hr>
</body>
</html>
