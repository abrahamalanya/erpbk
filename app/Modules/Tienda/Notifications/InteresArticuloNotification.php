<?php

namespace App\Modules\Tienda\Notifications;

use App\Modules\Tienda\Models\InteresArticulo;
use Illuminate\Notifications\Notification;

/**
 * Sent to the administradores who control the articulo's agencia when
 * someone expresses interest through the public tienda. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar() — see that
 * class / project_realtime_reverb memory for why (Laravel's own "broadcast"
 * notification channel needs a queue worker this project doesn't have).
 */
class InteresArticuloNotification extends Notification
{
    public function __construct(public readonly InteresArticulo $interes) {}

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
        $articulo = $this->interes->articulo;
        $etiqueta = $articulo?->nombre ?? "#{$this->interes->articulo_id}";

        return [
            'mensaje' => "{$this->interes->nombre} ({$this->interes->telefono}) está interesado en \"{$etiqueta}\".",
            'articulo_type' => $this->interes->articulo_type,
            'articulo_id' => $this->interes->articulo_id,
            'interes_id' => $this->interes->id,
        ];
    }
}
