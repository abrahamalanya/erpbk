<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito when un admin corrige el
 * número de cuotas — el cronograma completo se recalcula, debe enterarse.
 * Dispatched via App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoNumeroCuotasActualizadoNotification extends Notification
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
            'mensaje' => "El número de cuotas de tu crédito #{$this->credito->id} fue corregido a {$this->credito->numero_cuotas}; su cronograma se actualizó.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
