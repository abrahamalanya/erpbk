<?php

namespace App\Modules\Ruta\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trazado por las calles de una ruta de cobranza: el recorrido en vehículo
 * que une las paradas en su orden de visita, calculado por OpenRouteService
 * (mismos datos de OpenStreetMap que el mapa).
 *
 * El trazado de un mismo recorrido se guarda en caché una semana: las
 * coordenadas de los clientes casi no cambian, y así abrir el mapa no gasta
 * cupo del servicio (~2,000 rutas/día en el plan gratuito).
 *
 * Si no hay clave configurada o el servicio falla (sin internet, cupo
 * agotado, un cliente lejos de cualquier calle), ese tramo vuelve a la línea
 * recta y la respuesta queda marcada como `aproximado`: el mapa nunca se
 * queda sin ruta.
 */
final class TrazadoRutaService
{
    private const ENDPOINT = 'https://api.openrouteservice.org/v2/directions/driving-car/geojson';

    /**
     * Máximo de paradas por consulta que acepta OpenRouteService.
     */
    private const MAX_PARADAS = 50;

    private const CACHE_DIAS = 7;

    /**
     * @param  list<array{0: float, 1: float}>  $paradas  [latitud, longitud] en orden de visita
     * @return array{trazado: list<array{0: float, 1: float}>, distancia_m: int, duracion_s: int, aproximado: bool}
     */
    public function trazar(array $paradas): array
    {
        if (count($paradas) < 2) {
            return ['trazado' => $paradas, 'distancia_m' => 0, 'duracion_s' => 0, 'aproximado' => false];
        }

        $trazado = [];
        $distancia = 0;
        $duracion = 0;
        $aproximado = false;

        // Tramos de hasta MAX_PARADAS que comparten la parada del corte, para
        // que el recorrido quede continuo.
        for ($inicio = 0; $inicio < count($paradas) - 1; $inicio += self::MAX_PARADAS - 1) {
            $tramo = array_slice($paradas, $inicio, self::MAX_PARADAS);
            $resultado = $this->trazarTramo($tramo) ?? ['trazado' => $tramo, 'distancia_m' => 0, 'duracion_s' => 0, 'aproximado' => true];

            $trazado = [...$trazado, ...($trazado === [] ? $resultado['trazado'] : array_slice($resultado['trazado'], 1))];
            $distancia += $resultado['distancia_m'];
            $duracion += $resultado['duracion_s'];
            $aproximado = $aproximado || $resultado['aproximado'];
        }

        return ['trazado' => $trazado, 'distancia_m' => $distancia, 'duracion_s' => $duracion, 'aproximado' => $aproximado];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $tramo
     * @return array{trazado: list<array{0: float, 1: float}>, distancia_m: int, duracion_s: int, aproximado: bool}|null
     */
    private function trazarTramo(array $tramo): ?array
    {
        $clave = config('services.openrouteservice.key');

        if (! $clave) {
            return null;
        }

        $cacheKey = 'ruta-trazado:'.md5(json_encode(array_map(
            fn (array $p): string => sprintf('%.6f,%.6f', $p[0], $p[1]),
            $tramo,
        )));

        if ($guardado = Cache::get($cacheKey)) {
            return $guardado;
        }

        try {
            $respuesta = Http::timeout(15)
                ->withHeaders(['Authorization' => $clave])
                ->post(self::ENDPOINT, [
                    // OpenRouteService usa [longitud, latitud].
                    'coordinates' => array_map(fn (array $p): array => [$p[1], $p[0]], $tramo),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('OpenRouteService no respondió', ['error' => $e->getMessage()]);

            return null;
        }

        $ruta = $respuesta->json('features.0');

        if (! $respuesta->successful() || ! is_array($ruta)) {
            Log::warning('OpenRouteService no pudo trazar la ruta', [
                'status' => $respuesta->status(),
                'error' => $respuesta->json('error.message') ?? $respuesta->body(),
            ]);

            return null;
        }

        $resultado = [
            'trazado' => array_map(fn (array $c): array => [(float) $c[1], (float) $c[0]], $ruta['geometry']['coordinates'] ?? []),
            'distancia_m' => (int) round($ruta['properties']['summary']['distance'] ?? 0),
            'duracion_s' => (int) round($ruta['properties']['summary']['duration'] ?? 0),
            'aproximado' => false,
        ];

        Cache::put($cacheKey, $resultado, now()->addDays(self::CACHE_DIAS));

        return $resultado;
    }
}
