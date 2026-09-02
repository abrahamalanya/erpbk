<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito when an admin le edita la
 * tasa de interés — cambia un término de su crédito, debe enterarse.
 * Dispatched via App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoInteresActualizadoNotification extends Notification
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
            'mensaje' => "La tasa de interés de tu crédito #{$this->credito->id} fue actualizada a {$this->credito->interes}%.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
