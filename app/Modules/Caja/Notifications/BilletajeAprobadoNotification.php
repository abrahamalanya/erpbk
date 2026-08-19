<?php

namespace App\Modules\Caja\Notifications;

use App\Modules\Caja\Models\Billetaje;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever solicitó the billetaje once it's aprobado, so they know
 * the cash is ready without having to keep checking the module. Dispatched
 * via App\Modules\Sistemas\Services\NotificacionService::enviar() — see
 * that class / project_realtime_reverb memory for why (Laravel's own
 * "broadcast" notification channel needs a queue worker this project
 * doesn't have).
 */
class BilletajeAprobadoNotification extends Notification
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
        return [
            'mensaje' => "Tu solicitud de billetaje de S/ {$this->billetaje->monto} fue aprobada.",
            'url' => '/billetajes',
            'billetaje_id' => $this->billetaje->id,
        ];
    }
}
