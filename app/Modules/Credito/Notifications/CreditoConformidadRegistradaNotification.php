<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor and the controlling administrador once the conformidad
 * del notario/abogado (PDF) queda registrada sobre un crédito en
 * "pendiente_conformidad" — ya puede enviarse a la tienda.
 */
class CreditoConformidadRegistradaNotification extends Notification
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
            'mensaje' => "Se registró la conformidad del notario/abogado del crédito #{$this->credito->id}. Ya puede enviarse a la tienda.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
