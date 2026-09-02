<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Constancia Fotográfica #{{ $credito->id }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 15px; text-align: center; margin-bottom: 20px; }
        h2 { font-size: 12px; text-align: center; margin: 18px 0 8px; }
        h3 { font-size: 10.5px; text-align: center; font-weight: bold; margin: 10px 0 4px; }
        .foto-bloque { text-align: center; margin-bottom: 14px; }
        .foto-bloque img { max-width: 320px; max-height: 260px; border: 1px solid #ccc; }
        .sin-fotos { text-align: center; color: #777; font-style: italic; }
        .firma-linea { margin-top: 60px; border-top: 1px solid #000; width: 260px; text-align: center; padding-top: 4px; margin-left: auto; margin-right: auto; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    @php
        $clienteNombre = strtoupper($credito->cliente->nombre.' '.$credito->cliente->apellido);
        $tipoDoc = strtoupper($credito->cliente->tipo_documento);
    @endphp

    <h1>CONSTANCIA FOTOGR&Aacute;FICA DEL(LOS) BIEN(ES) EN GARANT&Iacute;A</h1>

    @foreach ($garantias as $bien)
        @php
            $bienTitulo = strtoupper($bien->nombre);
            if ($bien->marca) {
                $bienTitulo .= ' — '.$bien->marca;
            }
            if ($bien->modelo) {
                $bienTitulo .= ' '.$bien->modelo;
            }
        @endphp
        <h2>{{ $bienTitulo }}</h2>

        @php $fotoClienteUri = $fotoDataUri($bien->foto_cliente_producto_path); @endphp
        <h3>FOTO CON CLIENTE:</h3>
        @if ($fotoClienteUri)
            <div class="foto-bloque">
                <img src="{{ $fotoClienteUri }}">
            </div>
        @else
            <p class="sin-fotos">Sin foto con cliente registrada.</p>
        @endif

        <h3>FOTOS DEL PRODUCTO:</h3>
        @if ($bien->fotos->isEmpty())
            <p class="sin-fotos">Sin fotos del producto registradas.</p>
        @else
            @foreach ($bien->fotos as $foto)
                @php $fotoProductoUri = $fotoDataUri($foto->path); @endphp
                @if ($fotoProductoUri)
                    <div class="foto-bloque">
                        <img src="{{ $fotoProductoUri }}">
                    </div>
                @endif
            @endforeach
        @endif

        @unless ($loop->last)
            <div class="page-break"></div>
        @endunless
    @endforeach

    <div class="firma-linea">
        {{ $clienteNombre }}<br>
        {{ $tipoDoc }} N&deg;: {{ $credito->cliente->numero_documento }}
    </div>
</body>
</html>
