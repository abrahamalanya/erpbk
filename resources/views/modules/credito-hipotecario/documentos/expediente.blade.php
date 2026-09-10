<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Expediente — Crédito #{{ $credito->id }}</title>
    <style>
        @page { margin: 2cm 1.6cm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1a1a1a; }
        .exp-persona-titulo { font-size: 20px; font-weight: bold; text-align: center; margin: 8px 0 4px; }
        .exp-rol { font-size: 12px; text-align: center; color: #444; margin-bottom: 14px; }
        .exp-datos { width: 70%; margin: 0 auto; border-collapse: collapse; }
        .exp-datos td { padding: 3px 6px; font-size: 10.5px; }
        .exp-datos td.k { width: 38%; font-weight: bold; }
        .exp-seccion-titulo { font-size: 13px; font-weight: bold; text-align: center; margin: 6px 0 12px; letter-spacing: 1px; }
        .exp-foto { text-align: center; margin-bottom: 14px; }
        .exp-foto img { max-width: 480px; max-height: 620px; border: 1px solid #bbb; }
        .exp-pendiente { text-align: center; color: #999; font-style: italic; font-size: 12px; margin-top: 40px; }
        .exp-hoja h2 { font-size: 12px; text-align: center; margin: 0 0 14px; }
        .exp-hoja table { width: 100%; border-collapse: collapse; }
        .exp-hoja td { border: 1px solid #999; padding: 4px 6px; }
        .exp-hoja td.k { width: 42%; font-weight: bold; background: #f2f2f2; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    @php
        use Illuminate\Support\Str;

        $emp = $credito->empresa;
        $inmueble = $credito->inmuebles->first();
        $ficha = $credito->cliente->fichaSocioeconomica;

        // Personas del expediente: deudor + hasta 2 garantes.
        $personas = collect([
            ['rol' => 'deudor', 'etiqueta' => 'DEUDOR', 'cliente' => $credito->cliente, 'ocupacion' => $ficha?->profesion],
            ['rol' => 'aval1', 'etiqueta' => 'GARANTE', 'cliente' => $credito->aval, 'ocupacion' => null],
            ['rol' => 'aval2', 'etiqueta' => 'GARANTE', 'cliente' => $credito->aval2, 'ocupacion' => null],
        ])->filter(fn ($p) => $p['cliente'] !== null)->values();

        $seccionLabels = [
            'dni' => 'DNI',
            'casa' => 'FOTOS DE LA CASA',
            'ubicacion_maps' => 'UBICACIÓN (GOOGLE MAPS)',
            'croquis' => 'CROQUIS',
            'terreno' => 'FOTOS DEL TERRENO',
            'trabajo' => 'FOTOS DEL TRABAJO DEL CLIENTE',
            'negocio' => 'FOTOS DEL NEGOCIO',
            'suministro' => 'FOTO DE SUMINISTRO',
            'recibo_servicio' => 'RECIBOS DE LUZ / AGUA',
            'central_riesgo' => 'CENTRAL DE RIESGO (EQUIFAX)',
            'copia_literal' => 'COPIA LITERAL',
            'certificado_literal' => 'CERTIFICADO LITERAL',
        ];

        $seccionesPersona = ['dni', 'casa', 'ubicacion_maps', 'croquis', 'terreno', 'trabajo', 'negocio', 'suministro', 'recibo_servicio', 'central_riesgo'];
        $seccionesInmueble = ['copia_literal', 'certificado_literal'];

        // Rutas de imágenes para un rol + sección: las subidas al expediente
        // más las que el propio cliente ya tiene registradas.
        $rutasDe = function (string $rol, string $seccion, $cliente) use ($credito) {
            $subidas = $credito->expedienteDocumentos
                ->where('rol', $rol)->where('seccion', $seccion)
                ->pluck('path')->all();

            $propias = [];
            if ($cliente) {
                if ($seccion === 'dni') {
                    $propias = array_filter([$cliente->foto_dni_path, $cliente->foto_dni_reverso_path]);
                } elseif ($seccion === 'casa') {
                    $propias = array_filter([$cliente->foto_casa_path]);
                } elseif ($seccion === 'negocio') {
                    $propias = array_filter([$cliente->foto_negocio_path]);
                }
            }

            return array_values(array_unique([...$propias, ...$subidas]));
        };
    @endphp

    {{-- ===================== PORTADA ===================== --}}
    @foreach ($personas as $p)
        @php($cl = $p['cliente'])
        <div class="exp-persona-titulo">{{ mb_strtoupper($cl->nombre.' '.$cl->apellido) }}</div>
        <div class="exp-rol">({{ $p['etiqueta'] }})</div>
        <table class="exp-datos">
            <tr><td class="k">DNI</td><td>: {{ $cl->numero_documento }}</td></tr>
            <tr><td class="k">Teléfono</td><td>: {{ $cl->telefono }}</td></tr>
            <tr><td class="k">Estado civil</td><td>: {{ mb_strtoupper((string) $cl->estado_civil) }}</td></tr>
            <tr><td class="k">Dirección</td><td>: {{ mb_strtoupper((string) $cl->direccion) }}</td></tr>
            <tr><td class="k">Ocupación</td><td>: {{ mb_strtoupper((string) $p['ocupacion']) }}</td></tr>
        </table>
        <div style="height: 24px;"></div>
    @endforeach

    {{-- ===================== FOTOS POR PERSONA ===================== --}}
    @foreach ($personas as $p)
        @foreach ($seccionesPersona as $seccion)
            @php($rutas = $rutasDe($p['rol'], $seccion, $p['cliente']))
            <div class="page-break"></div>
            <div class="exp-seccion-titulo">
                {{ $seccionLabels[$seccion] }} — {{ mb_strtoupper($p['cliente']->nombre.' '.$p['cliente']->apellido) }} ({{ $p['etiqueta'] }})
            </div>
            @forelse ($rutas as $ruta)
                @php($uri = $fotoDataUri($ruta))
                @if ($uri)
                    <div class="exp-foto"><img src="{{ $uri }}"></div>
                @endif
            @empty
                <p class="exp-pendiente">(PENDIENTE — {{ $seccionLabels[$seccion] }})</p>
            @endforelse
        @endforeach
    @endforeach

    {{-- ===================== CRONOGRAMA ===================== --}}
    <div class="page-break"></div>
    @include('modules.credito-prendario.documentos._cronograma_cuerpo')

    {{-- ===================== FICHA SOCIOECONÓMICA ===================== --}}
    <div class="page-break"></div>
    @include('modules.credito-hipotecario.documentos._ficha_cuerpo')

    {{-- ===================== DOCUMENTOS DEL INMUEBLE ===================== --}}
    @foreach ($seccionesInmueble as $seccion)
        @php($rutas = $rutasDe('inmueble', $seccion, null))
        <div class="page-break"></div>
        <div class="exp-seccion-titulo">{{ $seccionLabels[$seccion] }}</div>
        @forelse ($rutas as $ruta)
            @php($uri = $fotoDataUri($ruta))
            @if ($uri)
                <div class="exp-foto"><img src="{{ $uri }}"></div>
            @endif
        @empty
            <p class="exp-pendiente">(PENDIENTE — {{ $seccionLabels[$seccion] }})</p>
        @endforelse
    @endforeach

    {{-- ===================== HOJA DE DATOS PARA LA MINUTA ===================== --}}
    <div class="page-break"></div>
    <div class="exp-hoja">
        <h2>DATOS PARA LA MINUTA DE PRÉSTAMO CON GARANTÍA HIPOTECARIA</h2>
        <table>
            <tr><td class="k">Empresa</td><td>{{ mb_strtoupper($emp->razon_social ?: $emp->nombre) }}</td></tr>
            <tr><td class="k">RUC de la empresa</td><td>{{ $emp->ruc }}</td></tr>
            <tr><td class="k">Representante / apoderado legal</td><td>{{ mb_strtoupper((string) ($emp->apoderado_legal ?: $emp->representante_legal)) }}</td></tr>
            <tr><td class="k">Deudor</td><td>{{ mb_strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido) }} — DNI {{ $credito->cliente->numero_documento }}</td></tr>
            @if ($credito->aval)
                <tr><td class="k">Garante 1</td><td>{{ mb_strtoupper($credito->aval->nombre.' '.$credito->aval->apellido) }} — DNI {{ $credito->aval->numero_documento }}</td></tr>
            @endif
            @if ($credito->aval2)
                <tr><td class="k">Garante 2</td><td>{{ mb_strtoupper($credito->aval2->nombre.' '.$credito->aval2->apellido) }} — DNI {{ $credito->aval2->numero_documento }}</td></tr>
            @endif
            <tr><td class="k">Monto del préstamo</td><td>S/ {{ number_format($credito->monto_prestamo, 2) }}</td></tr>
            <tr><td class="k">Tasa de interés</td><td>{{ number_format($credito->interes, 2) }}%</td></tr>
            <tr><td class="k">Plazo</td><td>{{ $credito->numero_cuotas ?? $credito->cuotas->count() }} cuotas ({{ $credito->plazo_dias }} días)</td></tr>
            <tr><td class="k">N° de partida del predio</td><td>{{ $inmueble?->partida_registral }}</td></tr>
            <tr><td class="k">Oficina registral</td><td>{{ $inmueble?->oficina_registral }}</td></tr>
            <tr><td class="k">Dirección del predio</td><td>{{ mb_strtoupper((string) $inmueble?->direccion) }}</td></tr>
            <tr><td class="k">Valorización del inmueble</td><td>{{ $inmueble ? 'S/ '.number_format($inmueble->valorizacion, 2) : '' }}</td></tr>
            <tr><td class="k">Monto del gravamen</td><td>&nbsp;</td></tr>
            <tr><td class="k">N° de cuenta de la empresa (BCP)</td><td>&nbsp;</td></tr>
            <tr><td class="k">N° de cuenta del cliente (BCP)</td><td>&nbsp;</td></tr>
            <tr><td class="k">Estado</td><td>&nbsp;</td></tr>
            <tr><td class="k">Firmas de ambas partes</td><td>&nbsp;</td></tr>
        </table>
    </div>
</body>
</html>
