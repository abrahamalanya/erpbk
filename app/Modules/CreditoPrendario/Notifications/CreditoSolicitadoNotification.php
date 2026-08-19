<?php

namespace App\Modules\CreditoPrendario\Notifications;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever currently has authority to aprobar/rechazar a crédito, as
 * soon as an asesor lo registra — así saben que hay una solicitud pendiente
 * sin tener que refrescar la lista. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoSolicitadoNotification extends Notification
{
    public function __construct(public readonly CreditoPrendario $credito) {}

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
            'mensaje' => "Nueva solicitud de crédito prendario #{$this->credito->id} pendiente de revisión.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
