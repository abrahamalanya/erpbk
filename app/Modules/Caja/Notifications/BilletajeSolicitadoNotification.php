<?php

namespace App\Modules\Caja\Notifications;

use App\Modules\Caja\Models\Billetaje;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever currently controls the bóveda a billetaje was requested
 * against, so they notice the pending request without having to remember to
 * check the Billetajes module. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar() rather than
 * Notification::send()/$user->notify() — that would use Laravel's own
 * "broadcast" channel, which implements ShouldBroadcast (queued), and this
 * project has no queue worker running, so live delivery would never
 * actually happen. NotificacionService writes the database row directly and
 * fires a ShouldBroadcastNow event itself, same pattern as every other
 * real-time update in this app. via() is unused by that path but kept for
 * documentation/in case a future caller does use the standard flow.
 */
class BilletajeSolicitadoNotification extends Notification
{
    public function __construct(public readonly Billetaje $billetaje) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $solicitante = $this->billetaje->solicitadoPor;

        return [
            'mensaje' => "Solicitud de billetaje: S/ {$this->billetaje->monto} de {$solicitante->nombre} {$solicitante->apellido}",
            'url' => '/billetajes',
            'billetaje_id' => $this->billetaje->id,
        ];
    }
}
