<?php

namespace App\Modules\CreditoPrendario\Notifications;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor and the controlling administrador when
 * actualizarEstadosVencidos() transiciona un crédito de activo a vencido.
 * Dispatched via App\Modules\Sistemas\Services\NotificacionService::enviar()
 * — see that class / project_realtime_reverb memory for why (Laravel's own
 * "broadcast" notification channel needs a queue worker this project
 * doesn't have).
 */
class CreditoVencidoNotification extends Notification
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
            'mensaje' => "El crédito #{$this->credito->id} venció. Refrenda o liquídalo antes de que pase a venta.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
