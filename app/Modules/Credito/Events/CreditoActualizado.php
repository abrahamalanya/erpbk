<?php

namespace App\Modules\Credito\Events;

use App\Modules\Credito\Models\Credito;
use App\Modules\Usuario\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/**
 * Fired whenever a crédito's estado changes (solicitado, aprobado,
 * rechazado, subsanado) — broadcast synchronously (no queue worker in this
 * project) on the private channel of every user who should see it update
 * live, resolved by the caller rather than by this event itself. Mirrors
 * App\Modules\Caja\Events\BilletajeActualizado.
 */
class CreditoActualizado implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  Collection<int, User>  $destinatarios
     */
    public function __construct(
        public readonly Credito $credito,
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
        return 'credito-prendario.actualizado';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->credito->id,
            'estado' => $this->credito->estado,
            'cliente_id' => $this->credito->cliente_id,
            'registrado_por' => $this->credito->registrado_por,
            'motivo_rechazo' => $this->credito->motivo_rechazo,
        ];
    }
}
