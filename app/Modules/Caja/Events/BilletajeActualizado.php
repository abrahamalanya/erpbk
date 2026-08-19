<?php

namespace App\Modules\Caja\Events;

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Usuario\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/**
 * Fired whenever a billetaje's estado changes (solicitado, aprobado,
 * rechazado) — broadcast synchronously (no queue worker in this project)
 * on the private channel of every user who should see it update live,
 * resolved by the caller rather than by this event itself.
 */
class BilletajeActualizado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  Collection<int, User>  $destinatarios
     */
    public function __construct(
        public readonly Billetaje $billetaje,
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
        return 'billetaje.actualizado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->billetaje->id,
            'estado' => $this->billetaje->estado,
            'monto' => (string) $this->billetaje->monto,
            'solicitado_por' => $this->billetaje->solicitado_por,
            'aprobado_por' => $this->billetaje->aprobado_por,
            'boveda_id' => $this->billetaje->boveda_id,
        ];
    }
}
