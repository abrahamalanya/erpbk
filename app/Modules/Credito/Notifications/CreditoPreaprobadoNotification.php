<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to whoever currently has authority to dar la aprobación final de este
 * crédito diario (el administrador general, ver
 * CreditoHierarchyService::aprobadoresFinalesDe()) en cuanto el admin de
 * agencia lo preaprueba. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoPreaprobadoNotification extends Notification
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
            'mensaje' => "Crédito diario #{$this->credito->id} preaprobado, pendiente de tu aprobación final.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
