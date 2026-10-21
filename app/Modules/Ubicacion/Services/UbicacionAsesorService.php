<?php

namespace App\Modules\Ubicacion\Services;

use App\Modules\Ubicacion\Events\UbicacionAsesorActualizada;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Ubicacion\Models\UbicacionAsesorHistorial;
use App\Modules\Usuario\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class UbicacionAsesorService
{
    /**
     * Umbral de inactividad entre dos puntos consecutivos del historial a
     * partir del cual se considera una pausa (minutos).
     */
    private const UMBRAL_PAUSA_MINUTOS = 20;

    /**
     * Registra (o sobrescribe) la última ubicación de $asesor y dispara el
     * evento de broadcast en tiempo real. empresa_id/agencia_id se copian
     * del propio asesor, no vienen del request. Además, si el ping es más
     * reciente que el último guardado (evita reintentos/pings fuera de
     * orden), agrega una fila al historial append-only — la tabla en vivo
     * sigue sobrescribiéndose igual que antes.
     *
     * @param  array{latitud: float, longitud: float, precision_metros?: float|null, velocidad_ms?: float|null, capturado_en?: string|null}  $datos
     */
    public function registrar(User $asesor, array $datos): UbicacionAsesor
    {
        return DB::transaction(function () use ($asesor, $datos): UbicacionAsesor {
            $anterior = UbicacionAsesor::query()->where('user_id', $asesor->id)->first();
            $capturadoEn = $datos['capturado_en'] ?? now();

            $ubicacion = UbicacionAsesor::query()->updateOrCreate(
                ['user_id' => $asesor->id],
                [
                    'latitud' => $datos['latitud'],
                    'longitud' => $datos['longitud'],
                    'precision_metros' => $datos['precision_metros'] ?? null,
                    'capturado_en' => $capturadoEn,
                    'empresa_id' => $asesor->empresa_id,
                    'agencia_id' => $asesor->agencia_id,
                ]
            );

            if (! $anterior || $ubicacion->capturado_en->gt($anterior->capturado_en)) {
                UbicacionAsesorHistorial::query()->create([
                    'user_id' => $asesor->id,
                    'latitud' => $datos['latitud'],
                    'longitud' => $datos['longitud'],
                    'precision_metros' => $datos['precision_metros'] ?? null,
                    'velocidad_ms' => $datos['velocidad_ms'] ?? null,
                    'capturado_en' => $capturadoEn,
                    'empresa_id' => $asesor->empresa_id,
                    'agencia_id' => $asesor->agencia_id,
                ]);
            }

            event(new UbicacionAsesorActualizada($ubicacion, $asesor));

            return $ubicacion;
        });
    }

    /**
     * Últimas ubicaciones visibles para $actor, para pintar el mapa —
     * mismo alcance por rol que RutaCobranzaService::asesoresVisibles().
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function visiblesPara(User $actor): Collection
    {
        $query = UbicacionAsesor::query()->with('asesor');

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        } elseif ($actor->hasRole('supervisor')) {
            $query->whereHas('asesor', fn (Builder $q) => $q->where('supervisor_id', $actor->id));
        } elseif (! $actor->hasAnyRole(['sistemas', 'administrador_general', 'secretaria'])) {
            return collect();
        } elseif (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        return $query->get()->map(fn (UbicacionAsesor $ubicacion): array => [
            'asesor_id' => $ubicacion->user_id,
            'nombre' => $ubicacion->asesor->nombre,
            'apellido' => $ubicacion->asesor->apellido,
            'latitud' => $ubicacion->latitud,
            'longitud' => $ubicacion->longitud,
            'precision_metros' => $ubicacion->precision_metros,
            'capturado_en' => $ubicacion->capturado_en,
        ])->values();
    }

    /**
     * Asesores cuyo historial puede consultar $actor en el reporte — mismo
     * alcance por rol que visiblesPara(), pero devolviendo usuarios (no
     * ubicaciones) para poblar el selector aunque el asesor no haya enviado
     * ningún ping todavía.
     *
     * @return Collection<int, User>
     */
    public function asesoresVisiblesPara(User $actor): Collection
    {
        $query = User::role('asesor');

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        } elseif ($actor->hasRole('supervisor')) {
            $query->where('supervisor_id', $actor->id);
        } elseif (! $actor->hasAnyRole(['sistemas', 'administrador_general', 'secretaria'])) {
            return collect();
        } elseif (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        return $query->orderBy('nombre')->get();
    }

    /**
     * Historial de recorrido de $asesor entre $desde y $hasta (ambas fechas
     * inclusive, en la zona horaria de la app): los puntos registrados y un
     * resumen con inicio/fin de jornada, distancia estimada y pausas —
     * todo inferido de los propios puntos, sin ningún evento explícito de
     * "inicio"/"fin"/"pausa" guardado por el aplicativo móvil.
     *
     * @return array{puntos: list<array<string, mixed>>, resumen: array<string, mixed>}
     */
    public function historialDe(User $asesor, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $puntos = UbicacionAsesorHistorial::query()
            ->where('user_id', $asesor->id)
            ->whereBetween('capturado_en', [$desde->startOfDay(), $hasta->endOfDay()])
            ->orderBy('capturado_en')
            ->get();

        return [
            'puntos' => $puntos->map(fn (UbicacionAsesorHistorial $p): array => [
                'latitud' => $p->latitud,
                'longitud' => $p->longitud,
                'precision_metros' => $p->precision_metros,
                'velocidad_ms' => $p->velocidad_ms,
                'capturado_en' => $p->capturado_en,
            ])->all(),
            'resumen' => [
                'inicio' => $puntos->first()?->capturado_en,
                'fin' => $puntos->last()?->capturado_en,
                'distancia_m' => $this->distanciaTotalMetros($puntos),
                'pausas' => $this->detectarPausas($puntos),
            ],
        ];
    }

    /**
     * Suma de la distancia Haversine entre cada par de puntos consecutivos.
     *
     * @param  Collection<int, UbicacionAsesorHistorial>  $puntos
     */
    private function distanciaTotalMetros(Collection $puntos): float
    {
        $total = 0.0;

        $puntos->values()->sliding(2)->each(function (Collection $par) use (&$total): void {
            [$a, $b] = $par->values()->all();
            $total += $this->haversineMetros($a->latitud, $a->longitud, $b->latitud, $b->longitud);
        });

        return round($total, 2);
    }

    /**
     * Huecos de tiempo entre puntos consecutivos por encima del umbral de
     * pausa — la única señal disponible, sin ningún evento explícito de
     * pausa/pérdida de señal enviado por la app.
     *
     * @param  Collection<int, UbicacionAsesorHistorial>  $puntos
     * @return list<array{desde: string, hasta: string, duracion_min: int}>
     */
    private function detectarPausas(Collection $puntos): array
    {
        $pausas = [];

        $puntos->values()->sliding(2)->each(function (Collection $par) use (&$pausas): void {
            [$a, $b] = $par->values()->all();
            $minutos = $a->capturado_en->diffInMinutes($b->capturado_en);

            if ($minutos >= self::UMBRAL_PAUSA_MINUTOS) {
                $pausas[] = [
                    'desde' => $a->capturado_en->toISOString(),
                    'hasta' => $b->capturado_en->toISOString(),
                    'duracion_min' => $minutos,
                ];
            }
        });

        return $pausas;
    }

    /**
     * Distancia en metros entre dos coordenadas (fórmula de Haversine,
     * radio terrestre medio 6,371,000 m).
     */
    private function haversineMetros(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $radioTierra = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $radioTierra * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
