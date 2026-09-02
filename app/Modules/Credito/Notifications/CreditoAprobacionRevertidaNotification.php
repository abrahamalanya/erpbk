<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito when an admin revierte una
 * aprobación por error — vuelve a pendiente, así que el asesor no debe
 * asumir que ya puede desembolsar. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoAprobacionRevertidaNotification extends Notification
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
            'mensaje' => "La aprobación de tu crédito #{$this->credito->id} fue revertida, vuelve a estar pendiente.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
