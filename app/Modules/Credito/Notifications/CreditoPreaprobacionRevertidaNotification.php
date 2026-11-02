<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito diario cuando un admin
 * revierte una preaprobación por error — vuelve a pendiente, así que el
 * asesor no debe asumir que ya está lista para la aprobación final.
 * Dispatched via App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoPreaprobacionRevertidaNotification extends Notification
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
            'mensaje' => "La preaprobación de tu crédito #{$this->credito->id} fue revertida, vuelve a estar pendiente.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
