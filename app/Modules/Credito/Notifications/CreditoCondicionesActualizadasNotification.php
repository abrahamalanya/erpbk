<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito cuando un admin corrige su
 * tipo_interes / tipo_cuota / monto_prestamo antes del desembolso. Dispatched
 * via App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoCondicionesActualizadasNotification extends Notification
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
            'mensaje' => "Se actualizaron las condiciones del crédito #{$this->credito->id}.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
