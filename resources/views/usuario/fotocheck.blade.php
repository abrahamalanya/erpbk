<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Fotochecks</title>
    <style>
        /* A4 horizontal (297x210mm). Cada persona imprime DOS tarjetas
           independientes de 5.4x8.5cm cada una (parte 1 | parte 2, lado a
           lado) — no una sola tarjeta partida en dos mitades. Entran 2
           personas por fila (4 tarjetas) x 2 filas = 4 personas por hoja;
           dompdf corta la tabla sola al pasarse de la página, así que con más
           de 4 usuarios salen las hojas que hagan falta. */
        @page { margin: 6mm; size: A4 landscape; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7pt;
            color: #1a1a1a;
            margin: 0;
        }

        table.hoja { border-collapse: separate; border-spacing: 3mm 3mm; margin: 0 auto; }

        /* Una hoja por bloque. El page-break-after va en el DIV, no en la
            tabla: dompdf no interpreta el salto si cuelga de un <table>
            (con varias tarjetas salían todas apiladas en la primera página). */
        .salto { page-break-after: always; }
        .salto:last-child { page-break-after: auto; }

        /* Tarjeta: medidas FIJAS (5.4x8.5cm) — el tamaño real de impresión de
           CADA parte, no un porcentaje de una tarjeta combinada. */
        .tarjeta {
            width: 54mm;
            height: 85mm;
            box-sizing: border-box;
            border: 1.2pt solid #1a5da9;
            border-radius: 2mm;
            padding: 2mm;
            text-align: center;
        }

        /* ===== Parte 1: logo + foto + nombre + cargo + dni + agencia ===== */

        .logo { max-height: 9mm; max-width: 48mm; margin: 0 auto 2mm auto; }

        /* Marco de la foto: tamaño FIJO para que todas las tarjetas de la hoja
           queden alineadas aunque unas tengan foto y otras no. */
        .marco-foto {
            width: 32mm;
            height: 38mm;
            margin: 0 auto 2mm auto;
            border: 0.6pt solid #b9c9e0;
            background: #f4f7fb;
            overflow: hidden;
        }
        .marco-foto img { width: 100%; height: 100%; object-fit: cover; }

        /* Placeholder cuando el usuario no tiene foto cargada. */
        .sin-foto {
            width: 32mm;
            height: 38mm;
            margin: 0 auto 2mm auto;
            border: 0.6pt dashed #b9c9e0;
            background: #f4f7fb;
            color: #9aaec7;
            font-size: 6.5pt;
            text-align: center;
            line-height: 38mm;
        }

        /* Nombre y aviso comparten el mismo "marco" rojo resaltado. */
        .marco-rojo {
            background: #d32f2f;
            color: #ffffff;
            border-radius: 1mm;
            padding: 1.2mm 2mm;
            margin-bottom: 1.5mm;
        }

        .nombre {
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
            line-height: 1.15;
            word-wrap: break-word;
        }

        .cargo {
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            color: #1a5da9;
            margin-bottom: 1mm;
        }

        .dni, .agencia { font-size: 8pt; line-height: 1.25; margin-bottom: 1mm; }
        .agencia { margin-bottom: 0; text-transform: uppercase; }

        /* ===== Parte 2: aviso + QR — tarjeta propia, con más espacio libre
           que la 1 (solo 2 elementos), así que arranca más abajo para no
           quedar pegada al borde superior. ===== */

        .parte2 { margin-top: 7mm; }

        .aviso {
            font-size: 6.5pt;
            line-height: 1.3;
        }

        .marco-qr {
            width: 38mm;
            height: 38mm;
            margin: 3mm auto 0 auto;
            border: 0.6pt solid #b9c9e0;
        }
        .marco-qr img { width: 100%; height: 100%; }

        .sin-qr {
            width: 38mm;
            height: 38mm;
            margin: 3mm auto 0 auto;
            border: 0.6pt dashed #b9c9e0;
            color: #9aaec7;
            font-size: 6.5pt;
            text-align: center;
            line-height: 38mm;
        }

        .qr-leyenda { font-size: 5.5pt; color: #666; margin-top: 1.5mm; }
    </style>
</head>
<body>
    @foreach ($hojas as $hoja)
        <div class="{{ $loop->last ? '' : 'salto' }}">
        <table class="hoja">
            @for ($fila = 0; $fila < ceil($porHoja / 2); $fila++)
                <tr>
                    @for ($par = 0; $par < 2; $par++)
                        @php($tarjeta = $hoja->get($fila * 2 + $par))

                        {{-- ===== Tarjeta 1 de la persona: identidad ===== --}}
                        <td style="border: none; padding: 0; vertical-align: top;">
                            @if ($tarjeta)
                                <div class="tarjeta">
                                    @if ($tarjeta['logo_data_uri'])
                                        <img class="logo" src="{{ $tarjeta['logo_data_uri'] }}" alt="">
                                    @endif

                                    @if ($tarjeta['foto_data_uri'])
                                        <div class="marco-foto">
                                            <img src="{{ $tarjeta['foto_data_uri'] }}" alt="">
                                        </div>
                                    @else
                                        <div class="sin-foto">Sin foto</div>
                                    @endif

                                    <div class="marco-rojo">
                                        <div class="nombre">{{ $tarjeta['nombre'] }}</div>
                                    </div>

                                    <div class="cargo">{{ $tarjeta['cargo'] }}</div>
                                    <div class="dni">DNI: {{ $tarjeta['dni'] ?? '—' }}</div>
                                    @if ($tarjeta['agencia'])
                                        <div class="agencia">{{ $tarjeta['agencia'] }}</div>
                                    @endif
                                </div>
                            @endif
                        </td>

                        {{-- ===== Tarjeta 2 de la persona: aviso + QR ===== --}}
                        <td style="border: none; padding: 0; vertical-align: top;">
                            @if ($tarjeta)
                                <div class="tarjeta">
                                    <div class="parte2">
                                        <div class="marco-rojo">
                                            <div class="aviso">
                                                Documento de uso personal e intransferible. En caso de
                                                extravío y/o accidente comunicarse al TELÉFONO:
                                                {{ $tarjeta['telefono'] ?? '—' }}
                                            </div>
                                        </div>

                                        @if ($tarjeta['qr_data_uri'])
                                            <div class="marco-qr">
                                                <img src="{{ $tarjeta['qr_data_uri'] }}" alt="">
                                            </div>
                                            <div class="qr-leyenda">{{ $tarjeta['dni'] }}</div>
                                        @else
                                            <div class="sin-qr">Sin DNI</div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </table>
        </div>
    @endforeach
</body>
</html>
