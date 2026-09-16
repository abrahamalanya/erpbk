<?php

namespace App\Modules\Ubicacion\Events;

use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Usuario\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired cada vez que un asesor envía un nuevo ping de ubicación — broadcast
 * síncrono (no hay queue worker en esta app) a dos canales: el de la propia
 * empresa del asesor (para administradores/supervisores de esa empresa) y
 * uno aparte para el rol sistemas, que puede tener empresa_id null y ver
 * asesores de varias empresas a la vez.
 */
class UbicacionAsesorActualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly UbicacionAsesor $ubicacion,
        public readonly User $asesor,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('empresa.'.$this->ubicacion->empresa_id.'.ubicaciones-asesores'),
            new PrivateChannel('sistemas.ubicaciones-asesores'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ubicacion.actualizada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'asesor_id' => $this->ubicacion->user_id,
            'nombre' => $this->asesor->nombre,
            'apellido' => $this->asesor->apellido,
            'latitud' => $this->ubicacion->latitud,
            'longitud' => $this->ubicacion->longitud,
            'precision_metros' => $this->ubicacion->precision_metros,
            'capturado_en' => $this->ubicacion->capturado_en?->toISOString(),
        ];
    }
}
