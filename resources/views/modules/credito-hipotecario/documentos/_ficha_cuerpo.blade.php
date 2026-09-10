{{-- Cuerpo de la ficha socioeconómica. Lo usan tanto el documento
     "ficha_socioeconomica" como la sección de ficha dentro del "expediente".
     Todo está scopeado bajo .ficha-doc para no chocar con otros estilos. --}}
<style>
    .ficha-doc { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1a1a1a; line-height: 1.25; }
    .ficha-doc h1 { font-size: 13px; text-align: center; margin: 0 0 2px; letter-spacing: 1px; }
    .ficha-doc table { width: 100%; border-collapse: collapse; }
    .ficha-doc td, .ficha-doc th { border: 1px solid #000; padding: 2px 4px; vertical-align: top; }
    .ficha-doc .sec { background: #d9d9d9; font-weight: bold; font-size: 8.5px; }
    .ficha-doc .lbl { font-weight: bold; white-space: nowrap; }
    .ficha-doc .num { text-align: right; }
    .ficha-doc .noborder td { border: none; }
    .ficha-doc .colhdr { background: #eee; font-weight: bold; text-align: center; }
    .ficha-doc .obs { height: 46px; }
    .ficha-doc .firmas td { border: none; text-align: center; padding-top: 34px; }
    .ficha-doc .firma-linea { border-top: 1px solid #000; padding-top: 3px; }
</style>

<div class="ficha-doc">
    @php
        use Illuminate\Support\Str;

        $c = $credito->cliente;
        $f = $c->fichaSocioeconomica;
        $fecha = $documento->generado_at->format('d/m/Y');
        $ciudad = Str::after($credito->agencia->nombre, 'Agencia ');

        $g = fn ($v) => $v === null || $v === '' ? '' : e($v);
        $money = fn ($v) => number_format((float) ($v ?? 0), 2);
        $mk = fn (bool $on) => $on ? '[ X ]' : '[  &nbsp; ]';

        $sexos = ['m' => 'Masculino', 'f' => 'Femenino'];

        $viviendaCols = [
            'TENDENCIA' => ['campo' => 'viv_tenencia', 'ops' => [
                'propia' => 'Propia', 'alquilada' => 'Alquilada', 'hipoteca' => 'Hipotecada',
                'alojado' => 'Alojado', 'otros' => 'Otros',
            ]],
            'MATERIAL' => ['campo' => 'viv_material', 'ops' => [
                'noble_acabado' => 'Noble/Acabado', 'noble_construccion' => 'Noble/Construcción',
                'rustico' => 'Rústico/adobe/quincha', 'provisional' => 'Provisional/Prefabricado',
                'seminoble' => 'Seminoble',
            ]],
            'N° DE HABITACIONES' => ['campo' => 'viv_habitaciones', 'ops' => [
                'uno' => 'Uno', 'dos' => 'Dos', 'tres' => 'Tres', 'cuatro' => 'Cuatro', 'cinco_a_mas' => 'Cinco a más',
            ]],
            'TIPO' => ['campo' => 'viv_tipo', 'ops' => [
                'casa_independiente' => 'Casa Independiente', 'departamento' => 'Departamento',
                'multifamiliar' => 'Multifamiliar', 'quinta' => 'Quinta', 'cuarto_solo' => 'Cuarto Solo',
            ]],
        ];

        $redes = ['luz_electrica' => 'Luz Eléctrica', 'desague' => 'Desagüe', 'cable' => 'Cable', 'internet' => 'Internet'];
        $muebles = [
            'equipo_sonido' => 'Equipo de Sonido', 'refrigeradora' => 'Refrigeradora', 'licuadora' => 'Licuadora',
            'television' => 'Televisión', 'cocina_gas' => 'Cocina a Gas', 'lavadora' => 'Lavadora',
            'computadora' => 'Computadora', 'horno_microondas' => 'Horno Microondas', 'otros' => 'Otros',
        ];
        $redesSel = $f->viv_redes_servicio ?? [];
        $mueblesSel = $f->viv_bienes_muebles ?? [];

        $ingresos = [
            'Esposa o Cónyuge' => 'ing_conyuge', 'Renta 1 Categoría' => 'ing_renta1',
            'Renta 2 Categoría' => 'ing_renta2', 'Renta 3 Categoría' => 'ing_renta3',
            'Renta 4 Categoría' => 'ing_renta4', 'Renta 5 Categoría' => 'ing_renta5',
            'Otros no formales' => 'ing_otros_no_formales', 'Pensión Judicial' => 'ing_pension_judicial',
        ];
        $egresosPer = [
            'Alimentación' => 'egp_alimentacion', 'Créditos' => 'egp_creditos', 'Educación' => 'egp_educacion',
            'Pasajes' => 'egp_pasajes', 'Agua' => 'egp_agua', 'Luz' => 'egp_luz', 'Teléfono' => 'egp_telefono',
            'Salud' => 'egp_salud', 'Otros (deudas, pensiones)' => 'egp_otros', 'Impuestos' => 'egp_impuestos',
            'Cable' => 'egp_cable',
        ];
        $egresosNeg = [
            'Alquiler' => 'egn_alquiler', 'Equipo' => 'egn_equipo', 'Energía Eléctrica' => 'egn_energia_electrica',
            'Sueldos y cargas sociales' => 'egn_sueldos_cargas', 'Agua' => 'egn_agua', 'Luz' => 'egn_luz',
            'Atenciones al personal' => 'egn_atenciones_personal', 'Telefonía' => 'egn_telefonia',
            'Otros impuestos y tasas' => 'egn_otros_impuestos_tasas', 'Seguridad / Limpieza' => 'egn_seguridad_limpieza',
            'Suministros' => 'egn_suministros',
        ];
        $ecoRows = max(count($ingresos), count($egresosPer), count($egresosNeg));
        $ingK = array_keys($ingresos); $ingV = array_values($ingresos);
        $epK = array_keys($egresosPer); $epV = array_values($egresosPer);
        $enK = array_keys($egresosNeg); $enV = array_values($egresosNeg);

        $familiares = $f?->familiares ?? collect();
        $filasFam = max(6, $familiares->count());
    @endphp

    <table class="noborder" style="margin-bottom: 4px;">
        <tr>
            <td style="width: 33%;">&nbsp;</td>
            <td style="width: 34%; text-align: center;"><h1>FICHA SOCIOECON&Oacute;MICA</h1></td>
            <td style="width: 33%; text-align: right;">
                <strong>N&deg; {{ str_pad((string) $credito->id, 6, '0', STR_PAD_LEFT) }}</strong><br>
                {{ $ciudad }}, {{ $fecha }}
            </td>
        </tr>
    </table>

    {{-- 1. DATOS PERSONALES --}}
    <table>
        <tr><td class="sec" colspan="6">1.- DATOS PERSONALES</td></tr>
        <tr>
            <td class="lbl">Apellidos y nombres</td><td class="val" style="width: 24%;">{{ mb_strtoupper($c->apellido.' '.$c->nombre) }}</td>
            <td class="lbl">DNI</td><td class="val" style="width: 12%;">{{ $g($c->numero_documento) }}</td>
            <td class="lbl">Edad</td><td class="val" style="width: 12%;">{{ $c->edad !== null ? $c->edad : '' }}</td>
        </tr>
        <tr>
            <td class="lbl">Fecha de nacimiento</td><td class="val">{{ optional($c->fecha_nacimiento)->format('d/m/Y') }}</td>
            <td class="lbl">Estado civil</td><td class="val">{{ $g($c->estado_civil) }}</td>
            <td class="lbl">Sexo</td><td class="val">{{ $sexos[$c->sexo] ?? '' }}</td>
        </tr>
        <tr>
            <td class="lbl">Grado de instrucción</td><td class="val">{{ $g($f->grado_instruccion ?? null) }}</td>
            <td class="lbl">Profesión</td><td class="val">{{ $g($f->profesion ?? null) }}</td>
            <td class="lbl">Teléfono</td><td class="val">{{ $g($c->telefono) }}</td>
        </tr>
        <tr>
            <td class="lbl">Domicilio</td><td class="val">{{ $g($c->direccion) }}</td>
            <td class="lbl">Distrito</td><td class="val">{{ $g($c->distrito) }}</td>
            <td class="lbl">Provincia</td><td class="val">{{ $g($c->provincia) }}</td>
        </tr>
        <tr>
            <td class="lbl">Referencia de ubicación</td><td class="val">{{ $g($c->referencia) }}</td>
            <td class="lbl">Email</td><td class="val">{{ $g($c->email) }}</td>
            <td class="lbl">Departamento</td><td class="val">{{ $g($c->departamento) }}</td>
        </tr>
    </table>

    {{-- 2. DATOS LABORALES --}}
    <table style="margin-top: 4px;">
        <tr><td class="sec" colspan="6">2.- DATOS LABORALES</td></tr>
        <tr>
            <td class="lbl">Nombre de institución</td><td class="val" style="width: 40%;">{{ $g($f->laboral_institucion ?? null) }}</td>
            <td class="lbl">Cargo</td><td class="val">{{ $g($f->laboral_cargo ?? null) }}</td>
            <td class="lbl">Fecha ingreso</td><td class="val" style="width: 14%;">{{ optional($f?->laboral_fecha_ingreso)->format('d/m/Y') }}</td>
        </tr>
    </table>

    {{-- 3. DATOS FAMILIARES --}}
    <table style="margin-top: 4px;">
        <tr><td class="sec" colspan="5">3.- DATOS FAMILIARES (solo con quienes viven)</td></tr>
        <tr>
            <th class="colhdr" style="text-align: left; width: 34%;">Nombres y Apellidos</th>
            <th class="colhdr" style="width: 8%;">Edad</th>
            <th class="colhdr" style="width: 18%;">Parentesco</th>
            <th class="colhdr" style="width: 18%;">Estado Civil</th>
            <th class="colhdr" style="width: 22%;">Ocupación</th>
        </tr>
        @for ($i = 0; $i < $filasFam; $i++)
            @php($fam = $familiares[$i] ?? null)
            <tr>
                <td>{{ $fam ? mb_strtoupper($fam->nombres) : '' }}</td>
                <td class="num">{{ $fam?->edad }}</td>
                <td>{{ $g($fam?->parentesco) }}</td>
                <td>{{ $g($fam?->estado_civil) }}</td>
                <td>{{ $g($fam?->ocupacion) }}</td>
            </tr>
        @endfor
    </table>

    {{-- 4. DATOS ECONOMICOS --}}
    <table style="margin-top: 4px;">
        <tr><td class="sec" colspan="6">4.- DATOS ECONÓMICOS</td></tr>
        <tr>
            <th class="colhdr" colspan="2">Ingresos mensuales (S/)</th>
            <th class="colhdr" colspan="2">Egresos mensuales personales (S/)</th>
            <th class="colhdr" colspan="2">Egresos mensuales negocio (S/)</th>
        </tr>
        @for ($i = 0; $i < $ecoRows; $i++)
            <tr>
                <td>{{ $ingK[$i] ?? '' }}</td>
                <td class="num" style="width: 10%;">{{ isset($ingV[$i]) ? $money($f?->{$ingV[$i]}) : '' }}</td>
                <td>{{ $epK[$i] ?? '' }}</td>
                <td class="num" style="width: 10%;">{{ isset($epV[$i]) ? $money($f?->{$epV[$i]}) : '' }}</td>
                <td>{{ $enK[$i] ?? '' }}</td>
                <td class="num" style="width: 10%;">{{ isset($enV[$i]) ? $money($f?->{$enV[$i]}) : '' }}</td>
            </tr>
        @endfor
        <tr class="sec">
            <td>Total Ingresos S/.</td><td class="num">{{ $money($f?->total_ingresos) }}</td>
            <td>Total Egresos S/.</td><td class="num">{{ $money($f?->total_egresos_personales) }}</td>
            <td>Total Egresos S/.</td><td class="num">{{ $money($f?->total_egresos_negocio) }}</td>
        </tr>
    </table>
    <table style="margin-top: 2px; width: 55%;">
        <tr><td class="lbl">TOTAL INGRESO</td><td class="num" style="width: 30%;">{{ $money($f?->total_ingresos) }}</td></tr>
        <tr><td class="lbl">TOTAL EGRESO</td><td class="num">{{ $money(bcadd((string) ($f?->total_egresos_personales ?? 0), (string) ($f?->total_egresos_negocio ?? 0), 2)) }}</td></tr>
        <tr class="sec"><td>TOTAL</td><td class="num">{{ $money($f?->total_neto) }}</td></tr>
    </table>

    {{-- 5. DATOS DE LA VIVIENDA --}}
    <table style="margin-top: 4px;">
        <tr><td class="sec" colspan="4">5.- DATOS DE LA VIVIENDA</td></tr>
        <tr>
            @foreach ($viviendaCols as $titulo => $conf)
                <th class="colhdr" style="width: 25%;">{{ $titulo }}</th>
            @endforeach
        </tr>
        @php($maxOps = max(array_map(fn ($c) => count($c['ops']), $viviendaCols)))
        @for ($i = 0; $i < $maxOps; $i++)
            <tr>
                @foreach ($viviendaCols as $conf)
                    @php($ops = array_values($conf['ops']))
                    @php($keys = array_keys($conf['ops']))
                    <td>
                        @if (isset($ops[$i]))
                            <span class="mark">{!! $mk(($f?->{$conf['campo']}) === $keys[$i]) !!}</span> {{ $ops[$i] }}
                        @endif
                    </td>
                @endforeach
            </tr>
        @endfor
    </table>

    <table style="margin-top: 4px;">
        <tr>
            <td class="colhdr" style="width: 50%;">VIVIENDA</td>
            <td class="colhdr">REDES DE SERVICIO PÚBLICO</td>
        </tr>
        <tr>
            <td>
                <table class="noborder" style="width: 100%;">
                    <tr><td class="lbl" style="width: 45%;">N° pisos</td><td>{{ $f?->viv_nro_pisos }}</td>
                        <td class="lbl">Agua</td><td>{{ $g($f->viv_agua ?? null) }}</td></tr>
                    <tr><td class="lbl">Piso en que vive</td><td>{{ $f?->viv_piso_vive }}</td>
                        <td class="lbl">Teléfono</td><td>{{ $g($f->viv_telefono ?? null) }}</td></tr>
                </table>
            </td>
            <td>
                @foreach ($redes as $k => $label)
                    <span class="mark">{!! $mk(in_array($k, $redesSel, true)) !!}</span> {{ $label }}&nbsp;&nbsp;
                @endforeach
            </td>
        </tr>
    </table>

    <table style="margin-top: 4px;">
        <tr><td class="colhdr" colspan="4">BIENES MUEBLES</td></tr>
        @foreach (array_chunk($muebles, 4, true) as $fila)
            <tr>
                @foreach ($fila as $k => $label)
                    <td style="width: 25%;"><span class="mark">{!! $mk(in_array($k, $mueblesSel, true)) !!}</span> {{ $label }}</td>
                @endforeach
                @for ($j = count($fila); $j < 4; $j++)<td></td>@endfor
            </tr>
        @endforeach
        <tr>
            <td class="lbl" colspan="2">Total activo mueble</td><td class="num" colspan="2">S/ {{ $money($f?->viv_total_activo_mueble) }}</td>
        </tr>
        <tr>
            <td class="lbl" colspan="2">Total activo inmueble</td><td class="num" colspan="2">S/ {{ $money($f?->viv_total_activo_inmueble) }}</td>
        </tr>
    </table>

    {{-- Declarante --}}
    <table style="margin-top: 4px;">
        <tr><td class="sec" colspan="4">DATOS DEL DECLARANTE (si el contacto es con otro familiar)</td></tr>
        <tr>
            <td class="lbl" style="width: 18%;">Apellidos y nombres</td><td style="width: 40%;">{{ $g($f->declarante_nombres ?? null) }}</td>
            <td class="lbl" style="width: 14%;">Parentesco</td><td>{{ $g($f->declarante_parentesco ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Dirección</td><td>{{ $g($f->declarante_direccion ?? null) }}</td>
            <td class="lbl">Teléfono</td><td>{{ $g($f->declarante_telefono ?? null) }}</td>
        </tr>
    </table>

    <table style="margin-top: 4px;">
        <tr><td class="sec">OBSERVACIONES</td></tr>
        <tr><td class="obs">{{ $g($f->observaciones ?? null) }}</td></tr>
    </table>

    <table class="firmas" style="margin-top: 10px;">
        <tr>
            <td style="width: 50%;"><div class="firma-linea">{{ mb_strtoupper($c->apellido.' '.$c->nombre) }}<br>Declarante · DNI {{ $g($c->numero_documento) }}</div></td>
            <td style="width: 50%;"><div class="firma-linea">{{ mb_strtoupper($g($f->responsable_ficha ?? null)) }}<br>Responsable de la ficha</div></td>
        </tr>
    </table>
</div>
