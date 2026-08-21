<?php

namespace App\Modules\Tienda\Notifications;

use App\Modules\Tienda\Models\InteresBien;
use Illuminate\Notifications\Notification;

/**
 * Sent to the administradores who control the bien's agencia when someone
 * expresses interest through the public tienda. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar() — see that
 * class / project_realtime_reverb memory for why (Laravel's own "broadcast"
 * notification channel needs a queue worker this project doesn't have).
 */
class InteresBienNotification extends Notification
{
    public function __construct(public readonly InteresBien $interes) {}

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
        $bien = $this->interes->bien;

        return [
            'mensaje' => "{$this->interes->nombre} ({$this->interes->telefono}) está interesado en el bien #{$bien->id} ({$bien->nombre}).",
            'bien_id' => $bien->id,
            'interes_id' => $this->interes->id,
        ];
    }
}
