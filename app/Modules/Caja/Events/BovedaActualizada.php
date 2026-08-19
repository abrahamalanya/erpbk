<?php

namespace App\Modules\Caja\Events;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Usuario\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/**
 * Fired whenever a bóveda's apertura state or saldo changes (aperturar,
 * cerrar, reabrir, inyectar/traspaso, or a billetaje/cierre-de-caja landing
 * a movimiento) — broadcast to every admin who controls this bóveda (unlike
 * Caja, which has exactly one owner, a bóveda can have more than one
 * administrador_agencia/administrador_general watching it).
 */
class BovedaActualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  Collection<int, User>  $destinatarios
     */
    public function __construct(
        public readonly Boveda $boveda,
        public readonly ?string $saldoActual,
        public readonly Collection $destinatarios,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return $this->destinatarios
            ->unique('id')
            ->map(fn (User $user): Channel => new PrivateChannel('App.Models.User.'.$user->id))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'boveda.actualizada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->boveda->id,
            'ciclo_abierto' => $this->saldoActual !== null,
            'saldo_actual' => $this->saldoActual,
        ];
    }
}
