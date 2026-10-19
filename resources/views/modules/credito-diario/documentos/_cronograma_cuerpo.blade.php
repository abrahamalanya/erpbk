{{-- Cuerpo del cronograma de crédito diario. A diferencia del de
     prendario/vehicular/hipotecario (_cronograma_cuerpo de credito-prendario),
     la tabla de cuotas aquí es de abonos: no desglosa capital/interés, muestra
     lo efectivamente abonado por cuota (CreditoService::pagarCuotas()) y deja
     una columna de firma en blanco para el cliente. `$credito->cuotas` debe
     estar poblado (cuotas persistidas o instancias tentativas). --}}
<style>
    .crono-doc { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
    .crono-doc h1 { font-size: 16px; text-align: center; margin-bottom: 4px; }
    .crono-doc table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .crono-doc td, .crono-doc th { padding: 1px 1px; vertical-align: top; }
    .crono-doc td.label { width: 35%; font-weight: bold; text-transform: uppercase; }
    .crono-doc .cuotas th,
    .crono-doc .cuotas td { border: 1px solid #ccc; padding: 4px 5px; }
    .crono-doc .cuotas th { text-align: left; background-color: #f3f3f3; font-weight: bold; }
    .crono-doc .cuotas .totales td { border-top: 2px solid #999; font-weight: bold; }
    .crono-doc .num { text-align: right; }
    .crono-doc .firma { min-width: 70px; }
    .crono-doc .tentativo { text-align: center; color: #b45309; font-weight: bold; margin: 4px 0 0; }
</style>

<div class="crono-doc">
    @php($tentativo = $tentativo ?? false)
    <h1>CRONOGRAMA DE CUOTAS</h1>
    @if ($tentativo)
        <p class="tentativo">CRONOGRAMA TENTATIVO — estimado con desembolso hoy; las fechas y montos definitivos se fijan al desembolsar.</p>
    @endif

    @php($qrYape = $fotoDataUri($credito->registradoPor?->qr_yape_path, 300))
    <table>
        <tr>
            <td class="label" colspan="4">{{ $credito->empresa->nombre }} — {{ $credito->agencia->nombre }}</td>
            <td class="label" style="text-align: center; width: 200px;">{{ $credito->registradoPor ? "{$credito->registradoPor->nombre} {$credito->registradoPor->apellido}" : '—' }}</td>
        </tr>
        <tr>
            <td class="label" style="width: 100px;">Cliente</td>
            <td colspan="3">{{ $credito->cliente->nombre }} {{ $credito->cliente->apellido }}</td>
            <td rowspan="4" style="text-align: center; vertical-align: middle;">
                @if ($qrYape)
                    <img src="{{ $qrYape }}" alt="QR Yape" width="90">
                @endif
            </td>
        </tr>
        <tr>
            <td class="label">DNI</td>
            <td>{{ $credito->cliente->numero_documento }}</td>
            <td class="label">Total a Pagar</td>
            <td>{{ number_format($credito->cuotas->sum('monto_total'), 2) }}</td>
        </tr>
        <tr>
            <td class="label">Plazo</td>
            <td>{{ $credito->plazo_dias }} días</td>
            <td class="label">Fecha de desembolso</td>
            <td>{{ optional($credito->fecha_desembolso)->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td class="label">Tipo de cuota</td>
            <td>{{ ucfirst($credito->tipo_cuota) }}</td>
            <td class="label">Fecha de vencimiento</td>
            <td>{{ optional($credito->fecha_vencimiento)->format('d/m/Y') }}</td>
        </tr>
    </table>
    <table class="cuotas">
        <tr>
            <th>N.º</th>
            <th>Vencimiento</th>
            <th>Fecha abono</th>
            <th class="num">Cuota</th>
            <th class="num">Abono</th>
            <th class="num">Saldo</th>
            <th class="firma">Firma</th>
        </tr>
        @foreach ($credito->cuotas->sortBy('numero_cuota') as $cuota)
        @php($abonado = (string) ($cuota->monto_abonado ?? '0.00'))
        @php($hayAbono = bccomp($abonado, '0', 2) > 0)
        <tr>
            <td>{{ $cuota->numero_cuota }}</td>
            <td>{{ optional($cuota->fecha_vencimiento)->format('d/m/Y') }}</td>
            <td>{{ optional($cuota->pagada_at)->format('d/m/Y') ?? '' }}</td>
            <td class="num">{{ number_format($cuota->monto_total, 2) }}</td>
            <td class="num">{{ $hayAbono ? number_format((float) $abonado, 2) : '' }}</td>
            <td class="num">{{ $hayAbono ? number_format((float) bcsub((string) $cuota->monto_total, $abonado, 2), 2) : '' }}</td>
            <td class="firma"></td>
        </tr>
        @endforeach
        <tr class="totales">
            <td colspan="3">Total</td>
            <td class="num">{{ number_format($credito->cuotas->sum('monto_total'), 2) }}</td>
            <td class="num">{{ number_format($credito->cuotas->sum('monto_abonado'), 2) }}</td>
            <td class="num"></td>
            <td></td>
        </tr>
    </table>
    <p>Estimado cliente, estar al día en sus pagos es importante para sus siguientes créditos.</p>
</div>
