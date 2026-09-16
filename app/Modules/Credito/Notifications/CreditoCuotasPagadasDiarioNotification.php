<?php

namespace App\Modules\Credito\Notifications;

use App\Modules\Credito\Models\Credito;
use Illuminate\Notifications\Notification;

/**
 * Sent to the asesor who registered the crédito original cuando otro
 * usuario paga una o varias cuotas de su crédito diario — a diferencia de
 * refrendar/adendar/pagarCuota, el crédito no cambia de id (no hay
 * sucesor). Dispatched via App\Modules\Sistemas\Services\NotificacionService::enviar().
 */
class CreditoCuotasPagadasDiarioNotification extends Notification
{
    public function __construct(public readonly Credito $credito, public readonly int $numeroCuotas) {}

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
        $texto = $this->numeroCuotas === 1 ? 'Se pagó 1 cuota' : "Se pagaron {$this->numeroCuotas} cuotas";

        return [
            'mensaje' => "{$texto} del crédito diario #{$this->credito->id}.",
            'url' => '/creditos-prendarios',
            'credito_id' => $this->credito->id,
        ];
    }
}
