<?php

namespace App\Nucleo\Support;

/**
 * Cálculos geográficos compartidos. Vive en el núcleo porque lo usan varios
 * módulos (Ubicacion, para el historial de recorridos; Ruta, para la
 * detección de permanencia del asesor cerca del cliente) y no pertenece a
 * ninguno de los dos en particular.
 */
final class Geo
{
    /**
     * Distancia en metros entre dos coordenadas (fórmula de Haversine,
     * radio terrestre medio 6,371,000 m).
     */
    public static function distanciaMetros(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $radioTierra = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $radioTierra * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
