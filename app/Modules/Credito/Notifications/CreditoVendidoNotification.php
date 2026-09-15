<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent when the vehículo ejecutado de un crédito vehicular en_venta se
 * transfiere a un comprador (CreditoService::vender()) — cierra
 * definitivamente el ciclo de vida del crédito.
 * Dispatched via App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoVendidoNotification extends Notification
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
            'mensaje' => "El vehículo del crédito #{$this->credito->id} fue transferido a un comprador; se generó el contrato de transferencia.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
