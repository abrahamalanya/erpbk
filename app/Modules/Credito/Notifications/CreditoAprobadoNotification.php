<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito once it's aprobado, so they
 * know to come back and desembolsar it. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar() — see that
 * class / project_realtime_reverb memory for why (Laravel's own "broadcast"
 * notification channel needs a queue worker this project doesn't have).
 */
class CreditoAprobadoNotification extends Notification
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
            'mensaje' => "Tu crédito #{$this->credito->id} fue aprobado. Ya puedes desembolsarlo.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
