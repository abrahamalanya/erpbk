<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pagaré — Crédito #{{ $credito->id }}</title>
    <style>
        @page { margin: 1cm; size: landscape; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.3px; color: #1a1a1a; line-height: 1.25; }
        .pagare-copia h1 { font-size: 10.5px; text-align: center; margin: 0 0 6px; }
        .pagare-copia p { text-align: justify; margin: 3px 0; }
        table.dos-copias { width: 100%; border-collapse: collapse; }
        table.dos-copias > tr > td { width: 50%; vertical-align: top; padding: 0 8px; }
        table.dos-copias > tr > td:first-child { border-right: 1px solid #999; }
        table.firmas { width: 100%; margin-top: 14px; }
        table.firmas td { width: 50%; text-align: center; vertical-align: bottom; font-size: 7.8px; }
        .firma-imagen { max-height: 45px; max-width: 140px; }
        .firma-linea { margin-top: 4px; border-top: 1px solid #000; padding-top: 2px; }
        .firma-espacio { height: 42px; }
    </style>
</head>
<body>
    @php
        $ciudad = \Illuminate\Support\Str::after($credito->agencia->nombre, 'Agencia ');
        $fecha = $documento->generado_at;
        $fechaTexto = "a los {$fecha->day} días del mes de ".$fecha->locale('es')->translatedFormat('F')." del {$fecha->year}";

        $clienteNombre = strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido);
        $tipoDoc = strtoupper($credito->cliente->tipo_documento);
        $empresaNombre = strtoupper($credito->empresa->razon_social ?: $credito->empresa->nombre);

        $cuotas = $credito->cuotas->sortBy('numero_cuota')->values();
        $primeraCuota = $cuotas->first();
        $ultimaCuota = $cuotas->last();
        $numeroCuotas = $cuotas->count();
        $montoCuota = $primeraCuota->monto_total ?? '0';

        $fechaPrimeraCuota = $primeraCuota ? $primeraCuota->fecha_vencimiento->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y') : '—';
        $fechaUltimaCuota = $ultimaCuota ? $ultimaCuota->fecha_vencimiento->locale('es')->translatedFormat('d \\d\\e F \\d\\e\\l Y') : '—';

        $tipoCuotaLabel = [
            'diario' => 'diarias',
            'semanal' => 'semanales',
            'quincenal' => 'quincenales',
            'mensual' => 'mensuales',
        ][$credito->tipo_cuota] ?? $credito->tipo_cuota;

        $montoEnLetras = \App\Nucleo\Support\NumeroALetras::soles($credito->monto_prestamo);
        $montoCuotaEnLetras = \App\Nucleo\Support\NumeroALetras::soles($montoCuota);
    @endphp

    <table class="dos-copias">
        <tr>
            <td>@include('modules.credito-prendario.documentos._pagare_cuerpo')</td>
            <td>@include('modules.credito-prendario.documentos._pagare_cuerpo')</td>
        </tr>
    </table>
</body>
</html>
