<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever currently has authority to aprobar/rechazar a crédito
 * cuando el asesor lo subsana (reenvía a revisión tras un rechazo) — vuelve
 * a entrar a la cola de pendientes y necesitan saber que ya pueden
 * revisarlo de nuevo. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoSubsanadoNotification extends Notification
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
            'mensaje' => "El crédito prendario #{$this->credito->id} fue subsanado y reenviado a revisión.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
