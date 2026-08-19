<?php

namespace App\Modules\CreditoPrendario\Notifications;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito once queda desembolsado —
 * relevante sobre todo cuando otro usuario (ej. un administrador) ejecuta el
 * desembolso en su lugar. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoDesembolsadoNotification extends Notification
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
            'mensaje' => "Tu crédito #{$this->credito->id} fue desembolsado.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
