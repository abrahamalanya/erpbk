<?php

namespace App\Modules\CreditoPrendario\Notifications;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito once it's rechazado, so
 * sabe que debe revisar el motivo y subsanarlo. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoRechazadoNotification extends Notification
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
            'mensaje' => "Tu crédito #{$this->credito->id} fue rechazado: {$this->credito->motivo_rechazo}",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
