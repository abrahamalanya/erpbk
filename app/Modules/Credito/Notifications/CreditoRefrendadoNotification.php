<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito original cuando queda
 * refrendado — relevante sobre todo cuando otro usuario ejecuta el refrendo
 * en su lugar. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoRefrendadoNotification extends Notification
{
    public function __construct(public readonly Credito $credito) {}

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
            'mensaje' => "Tu crédito #{$this->credito->refrendo_de_credito_id} fue refrendado (nuevo crédito #{$this->credito->id}).",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
