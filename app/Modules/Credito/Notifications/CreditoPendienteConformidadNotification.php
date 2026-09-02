<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor and the controlling administrador when a vencido
 * crédito of a tipo que exige conformidad (vehicular / hipotecario) supera
 * los días de espera: en vez de pasar a "en_venta" queda en
 * "pendiente_conformidad" esperando el visto del notario/abogado.
 */
class CreditoPendienteConformidadNotification extends Notification
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
            'mensaje' => "El crédito #{$this->credito->id} superó los días de espera y necesita la conformidad del notario/abogado antes de pasar a la tienda.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
