<?php

namespace App\Modules\Sistemas\Events;

use App\Modules\Usuario\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Fired right after a DatabaseNotification is created for a user, so the
 * header bell updates live. NOT the same as Laravel's own
 * BroadcastNotificationCreated — that event implements ShouldBroadcast
 * (queued), and this project has no queue worker running, so it would never
 * actually deliver. Same reasoning as every other broadcast here: use
 * ShouldBroadcastNow and dispatch it ourselves instead.
 */
class NotificacionCreada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly User $destinatario,
        public readonly DatabaseNotification $notificacion,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->destinatario->id)];
    }

    public function broadcastAs(): string
    {
        return 'notificacion.creada';
    }

    /**
     * Matches the shape of GET /notificaciones's list items exactly, so the
     * frontend can use the same type for both without normalizing.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificacion->id,
            'type' => $this->notificacion->type,
            'data' => $this->notificacion->data,
            'read_at' => $this->notificacion->read_at,
            'created_at' => $this->notificacion->created_at?->toISOString(),
        ];
    }
}
