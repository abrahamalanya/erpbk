<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito hipotecario original cuando
 * queda refinanciado — un nuevo crédito pendiente nace con el capital
 * recalculado (capital + interés + mora, menos lo pagado/descontado) y debe
 * pasar por aprobar/firmar/desembolsar otra vez. Dispatched via
 * App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoRefinanciadoNotification extends Notification
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
            'mensaje' => "Tu crédito #{$this->credito->refinanciamiento_de_credito_id} fue refinanciado (nuevo crédito #{$this->credito->id}, pendiente de aprobación).",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
