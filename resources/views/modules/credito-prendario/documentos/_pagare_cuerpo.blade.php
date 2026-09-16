{{-- Cuerpo de UNA copia del pagaré. Se incluye dos veces desde pagare.blade.php
     (copia cliente / copia empresa en la misma hoja) y comparte el scope de
     variables ya calculadas por ese wrapper. --}}
<div class="pagare-copia">
    <h1>PAGARÉ Y RECONOCIMIENTO DE DEUDA</h1>

    <p>
        En la ciudad de {{ $ciudad }}, {{ $fechaTexto }}, yo, <strong>{{ $clienteNombre }}</strong>, identificado
        con {{ $tipoDoc }} N.° {{ $credito->cliente->numero_documento }},
        @if ($credito->cliente->direccion)
            con domicilio en {{ $credito->cliente->direccion }},
        @endif
        declaro haber recibido de la empresa <strong>{{ $empresaNombre }}</strong>, con RUC {{ $credito->empresa->ruc }},
        la suma de S/. {{ number_format($credito->monto_prestamo, 2) }} ({{ $montoEnLetras }}) en calidad de préstamo
        dinerario, monto que reconozco deber y pagar íntegramente.
    </p>

    <p>
        Me obligo a devolver dicha suma en {{ $numeroCuotas }} cuotas {{ $tipoCuotaLabel }} de
        S/. {{ number_format($montoCuota, 2) }} ({{ $montoCuotaEnLetras }}) cada una, iniciando el pago el día
        {{ $fechaPrimeraCuota }} y finalizando el día {{ $fechaUltimaCuota }}.
    </p>

    <p>Los pagos serán realizados en efectivo, Yape, Plin o depósito en la cuenta bancaria indicada.</p>

    <p>
        <strong>1. DOMICILIO CONTRACTUAL FIJO:</strong> El deudor fija como domicilio contractual válido y vigente
        el mencionado líneas arriba, donde se considerará válida legalmente realizada toda notificación física,
        judicial o notarial, aunque no resida ahí posteriormente.
    </p>
    <p>
        <strong>2. INTERÉS MORATORIO:</strong> En caso de retraso de cualquier cuota, se aplicará interés
        moratorio mensual sobre saldo pendiente, sin necesidad de aviso previo.
    </p>
    <p>
        <strong>3. PÉRDIDA DE PLAZO:</strong> El incumplimiento de 1 sola cuota faculta al acreedor a declarar
        vencimiento anticipado de la deuda y exigir el pago total inmediato del saldo.
    </p>
    <p>
        <strong>4. GASTOS DE COBRANZA:</strong> El deudor se obliga a asumir gastos notariales, costos
        procesales, honorarios de abogado y costos judiciales que genere la cobranza extrajudicial o judicial.
    </p>
    <p>
        <strong>5. CENTRALES DE RIESGO:</strong> Autorizo irrevocablemente al acreedor a reportar mis datos,
        deuda, atrasos y comportamiento financiero a INFOCORP (Equifax) o cualquier central de riesgo pública o
        privada.
    </p>
    <p>
        <strong>6. VÍA JUDICIAL:</strong> El presente documento constituye título ejecutivo, conforme a ley,
        siendo plenamente exigible vía proceso único de ejecución, sin necesidad de protesto previa.
    </p>
    <p>
        <strong>7. VALIDEZ DE COPIA:</strong> En caso de pérdida o destrucción del original, copia simple,
        digital o fotográfica del presente pagaré mantiene plena validez.
    </p>
    <p>
        <strong>8. NO REQUIERE CARTA DE COBRANZA:</strong> El deudor reconoce que este pagaré es exigible sin
        aviso previo, protesto o requerimiento.
    </p>

    <p>
        Finalmente, declaro haber leído, comprendido y aceptado plenamente este pagaré, firmándolo en señal de
        conformidad, obligándome al pago total dentro del plazo pactado.
    </p>

    <table class="firmas">
        <tr>
            <td>
                @if ($credito->empresa->firma_path)
                    <img class="firma-imagen" src="{{ $fotoDataUri($credito->empresa->firma_path, 400) }}">
                @else
                    <div class="firma-espacio"></div>
                @endif
                <div class="firma-linea">
                    EL PRESTAMISTA<br>
                    Representante de {{ $empresaNombre }}<br>
                    RUC: {{ $credito->empresa->ruc }}
                </div>
            </td>
            <td>
                <div class="firma-espacio"></div>
                <div class="firma-linea">
                    DEUDOR - OBLIGADO PRINCIPAL<br>
                    {{ $clienteNombre }}<br>
                    {{ $tipoDoc }}: {{ $credito->cliente->numero_documento }}
                </div>
            </td>
        </tr>
    </table>
</div>
