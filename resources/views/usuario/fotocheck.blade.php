<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Fotochecks</title>
    <style>
        /* A4 horizontal (297x210mm). 3 tarjetas arriba y 3 abajo = 6 por hoja;
           dompdf corta la tabla sola al pasarse de la página, así que con más
           de 6 usuarios salen las hojas que hagan falta. */
        @page { margin: 6mm; size: A4 landscape; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7pt;
            color: #1a1a1a;
            margin: 0;
        }

        table.hoja { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; }

        /* Una hoja por bloque. El page-break-after va en el DIV, no en la
            tabla: dompdf no interpreta el salto si cuelga de un <table>
            (con 13 tarjetas salían 2 hojas en vez de 3). */
        .salto { page-break-after: always; }
        .salto:last-child { page-break-after: auto; }

        table.tarjeta {
            width: 100%;
            border-collapse: collapse;
            border: 1.2pt solid #1a5da9;
            border-radius: 2mm;
        }

        /* Parte 1: identidad (foto + nombre + rol + dni).
           Parte 2: aviso de extravío + QR. */
        table.tarjeta td {
            vertical-align: top;
            padding: 2mm;
        }

        td.parte1 { width: 52%; border-right: 1.2pt solid #1a5da9; }
        td.parte2 { width: 48%; text-align: center; }

        .marca {
            font-size: 5.5pt;
            font-weight: bold;
            color: #1a5da9;
            letter-spacing: 0.4pt;
            text-transform: uppercase;
            margin-bottom: 1mm;
        }

        /* Marco de la foto: tamaño FIJO para que todas las tarjetas de la hoja
           queden alineadas aunque unas tengan foto y otras no. */
        .marco-foto {
            width: 26mm;
            height: 32mm;
            margin: 0 auto 1.5mm auto;
            border: 0.6pt solid #b9c9e0;
            background: #f4f7fb;
            overflow: hidden;
        }
        .marco-foto img { width: 100%; height: 100%; object-fit: cover; }

        /* Placeholder cuando el usuario no tiene foto cargada. */
        .sin-foto {
            width: 26mm;
            height: 32mm;
            margin: 0 auto 1.5mm auto;
            border: 0.6pt dashed #b9c9e0;
            background: #f4f7fb;
            color: #9aaec7;
            font-size: 6pt;
            text-align: center;
            line-height: 32mm;
        }

        .nombre {
            font-size: 8.5pt;
            font-weight: bold;
            text-transform: uppercase;
            line-height: 1.15;
            margin-bottom: 0.8mm;
            word-wrap: break-word;
        }

        .dato { font-size: 6.5pt; line-height: 1.3; }
        .dato strong { color: #1a5da9; }
        .valor { word-wrap: break-word; }

        .aviso {
            font-size: 5.6pt;
            line-height: 1.25;
            text-align: justify;
            color: #333;
            margin-bottom: 1.5mm;
        }

        .marco-qr {
            width: 20mm;
            height: 20mm;
            margin: 0 auto;
            border: 0.6pt solid #b9c9e0;
        }
        .marco-qr img { width: 100%; height: 100%; }

        .sin-qr {
            width: 20mm;
            height: 20mm;
            margin: 0 auto;
            border: 0.6pt dashed #b9c9e0;
            color: #9aaec7;
            font-size: 5.5pt;
            text-align: center;
            line-height: 20mm;
        }

        .qr-leyenda { font-size: 5pt; color: #666; margin-top: 1mm; }
    </style>
</head>
<body>
    @foreach ($hojas as $hoja)
        <div class="{{ $loop->last ? '' : 'salto' }}">
        <table class="hoja">
            <tr>
                @for ($columna = 1; $columna <= $porHoja; $columna++)
                    <td style="border: none; padding: 0; vertical-align: top;">
                        @php($tarjeta = $hoja->get($columna - 1))

                        @if ($tarjeta)
                            <table class="tarjeta">
                                {{-- ===== PARTE 1: identidad ===== --}}
                                <tr>
                                    <td class="parte1">
                                        <div class="marca">Fotocheck</div>

                                        @if ($tarjeta['foto_data_uri'])
                                            <div class="marco-foto">
                                                <img src="{{ $tarjeta['foto_data_uri'] }}" alt="">
                                            </div>
                                        @else
                                            <div class="sin-foto">Sin foto</div>
                                        @endif

                                        <div class="nombre">{{ $tarjeta['nombre'] }}</div>

                                        <div class="dato">
                                            <strong>Rol:</strong>
                                            <span class="valor">{{ $tarjeta['roles'] === [] ? '—' : ucfirst(implode(' / ', $tarjeta['roles'])) }}</span>
                                        </div>
                                        <div class="dato">
                                            <strong>DNI:</strong>
                                            <span class="valor">{{ $tarjeta['dni'] ?? '—' }}</span>
                                        </div>
                                        @if ($tarjeta['agencia'])
                                            <div class="dato">
                                                <strong>Agencia:</strong>
                                                <span class="valor">{{ $tarjeta['agencia'] }}</span>
                                            </div>
                                        @endif
                                    </td>

                                    {{-- ===== PARTE 2: aviso + QR ===== --}}
                                    <td class="parte2">
                                        <div class="aviso">
                                            Este documento es del personal e intransferible, en caso de
                                            extravío y/o accidente comunicarse al TELÉFONO:
                                            <strong>{{ $tarjeta['telefono'] ?? '—' }}</strong>
                                        </div>

                                        @if ($tarjeta['qr_data_uri'])
                                            <div class="marco-qr">
                                                <img src="{{ $tarjeta['qr_data_uri'] }}" alt="">
                                            </div>
                                            <div class="qr-leyenda">{{ $tarjeta['dni'] }}</div>
                                        @else
                                            <div class="sin-qr">Sin DNI</div>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        @endif
                    </td>
                @endfor
            </tr>
        </table>
        </div>
    @endforeach
</body>
</html>