<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mapa unificado (asesores en vivo + ruta de cobranza)
    |--------------------------------------------------------------------------
    |
    | Parámetros de la detección de permanencia (VisitaClienteService): a
    | cuántos metros del lugar de cobranza del cliente se considera que el
    | asesor "está ahí", cuántos minutos continuos confirman la visita, qué
    | tan mala puede ser la precisión del GPS antes de ignorar la lectura, y
    | qué hueco de tiempo entre lecturas se considera una interrupción real
    | (no solo pocas lecturas) que reinicia el contador.
    |
    */

    'radio_proximidad_metros' => (float) env('MAPA_RADIO_PROXIMIDAD_METROS', 100),

    'minutos_permanencia' => (int) env('MAPA_MINUTOS_PERMANENCIA', 3),

    'precision_maxima_metros' => (float) env('MAPA_PRECISION_MAXIMA_METROS', 75),

    'gap_maximo_segundos' => (int) env('MAPA_GAP_MAXIMO_SEGUNDOS', 150),

    /*
    |--------------------------------------------------------------------------
    | Ubicación del asesor desactualizada
    |--------------------------------------------------------------------------
    |
    | A partir de cuántos minutos sin un ping nuevo se le muestra al usuario
    | que la posición del asesor en el mapa ya no es confiable.
    |
    */

    'minutos_ubicacion_desactualizada' => (int) env('MAPA_MINUTOS_UBICACION_DESACTUALIZADA', 5),

];
