<?php

namespace App\Modules\Caja\Events;

use App\Modules\Caja\Models\Caja;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever a caja's apertura state or saldo changes (aperturar,
 * cerrar, reabrir, or a billetaje aprobado landing a movimiento) —
 * broadcast synchronously to the caja's own owner only, so a header badge
 * can show live estado/saldo without leaving the current module.
 */
class CajaActualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly Caja $caja,
        public readonly ?string $saldoActual,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->caja->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'caja.actualizada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->caja->id,
            'ciclo_abierto' => $this->saldoActual !== null,
            'saldo_actual' => $this->saldoActual,
        ];
    }
}
