<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito when un admin regulariza
 * (corrige) la fecha de desembolso — el cronograma completo se recalcula,
 * debe enterarse.
 * Dispatched via App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoFechaDesembolsoActualizadaNotification extends Notification
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
        $fecha = $this->credito->fecha_desembolso->format('d/m/Y');

        return [
            'mensaje' => "La fecha de desembolso de tu crédito #{$this->credito->id} fue corregida a {$fecha}; su cronograma se actualizó.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
