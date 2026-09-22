<?php

namespace App\Modules\Venta\Notifications;

use App\Modules\Venta\Models\Venta;
use Illuminate\Notifications\Notification;

/**
 * Enviada al registrar una venta de un art&iacute;culo de tienda, cualquiera
 * sea su forma_venta. Dispatched v&iacute;a
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class VentaRegistradaNotification extends Notification
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
            'mensaje' => "Se registró la venta #{$this->venta->id} ({$this->venta->forma_venta}) por S/ ".number_format((float) $this->venta->precio_venta, 2).'.',
            'url' => '/ventas',
            'venta_id' => $this->venta->id,
        ];
    }
}
