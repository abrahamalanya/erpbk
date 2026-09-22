<?php

namespace App\Modules\Venta\Notifications;

use App\Modules\Venta\Models\Venta;
use Illuminate\Notifications\Notification;

/**
 * Enviada cuando un apartado vence sin que el cliente cubra el saldo a
 * tiempo — VentaService::cancelarVencidas() la cancela y el art&iacute;culo
 * vuelve a la tienda.
 */
class VentaApartadoVencidaNotification extends Notification
{
    public function __construct(public readonly Venta $venta) {}

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
            'mensaje' => "El apartado de la venta #{$this->venta->id} venció sin cubrir el saldo: se canceló y el artículo volvió a la tienda.",
            'url' => '/ventas',
            'venta_id' => $this->venta->id,
        ];
    }
}
