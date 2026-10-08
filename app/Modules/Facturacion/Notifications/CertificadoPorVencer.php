<?php

namespace App\Modules\Facturacion\Notifications;

use App\Modules\Facturacion\Models\CredencialSunat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificadoPorVencer extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly CredencialSunat $credencial,
        public readonly int $diasRestantes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $empresa = $this->credencial->empresa;

        return (new MailMessage)
            ->subject("⚠️ Certificado digital por vencer - {$empresa->nombre}")
            ->greeting("Hola {$notifiable->name}")
            ->line("El certificado digital de la empresa **{$empresa->nombre}** (RUC: {$empresa->ruc}) vencerá en **{$this->diasRestantes} día(s)**.")
            ->line("Fecha de vencimiento: {$this->credencial->certificado_vence_en?->format('d/m/Y')}")
            ->line('')
            ->line('**Acción requerida:** Renovar el certificado digital ante la entidad certificadora acreditada por INDECOPI y subir el nuevo .pfx en Configuración → Facturación → Credenciales SUNAT.')
            ->line('')
            ->line('Mientras el certificado esté vencido, **no se podrán emitir comprobantes electrónicos**.')
            ->action('Ir a Credenciales SUNAT', url('/facturacion/credenciales'))
            ->salutation('Equipo de Facturación Electrónica');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'certificado_por_vencer',
            'empresa_id' => $this->credencial->empresa_id,
            'empresa_nombre' => $this->credencial->empresa->nombre,
            'ruc' => $this->credencial->empresa->ruc,
            'dias_restantes' => $this->diasRestantes,
            'vence_en' => $this->credencial->certificado_vence_en?->toDateString(),
            'mensaje' => "El certificado digital vence en {$this->diasRestantes} día(s). Renueve el .pfx antes de que expire.",
        ];
    }
}