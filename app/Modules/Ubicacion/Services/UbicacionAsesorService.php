<?php

namespace App\Modules\Ubicacion\Services;

use App\Modules\Ubicacion\Events\UbicacionAsesorActualizada;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class UbicacionAsesorService
{
    /**
     * Registra (o sobrescribe) la última ubicación de $asesor y dispara el
     * evento de broadcast en tiempo real. empresa_id/agencia_id se copian
     * del propio asesor, no vienen del request.
     *
     * @param  array{latitud: float, longitud: float, precision_metros?: float|null, capturado_en?: string|null}  $datos
     */
    public function registrar(User $asesor, array $datos): UbicacionAsesor
    {
        $ubicacion = UbicacionAsesor::query()->updateOrCreate(
            ['user_id' => $asesor->id],
            [
                'latitud' => $datos['latitud'],
                'longitud' => $datos['longitud'],
                'precision_metros' => $datos['precision_metros'] ?? null,
                'capturado_en' => $datos['capturado_en'] ?? now(),
                'empresa_id' => $asesor->empresa_id,
                'agencia_id' => $asesor->agencia_id,
            ]
        );

        event(new UbicacionAsesorActualizada($ubicacion, $asesor));

        return $ubicacion;
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
}
