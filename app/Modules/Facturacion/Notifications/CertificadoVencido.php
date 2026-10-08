<?php

namespace App\Modules\Facturacion\Notifications;

use App\Modules\Facturacion\Models\CredencialSunat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificadoVencido extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly CredencialSunat $credencial,
        public readonly int $diasVencido,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $empresa = $this->credencial->empresa;

        return (new MailMessage)
            ->subject("🚨 CERTIFICADO VENCIDO - Emisión BLOQUEADA - {$empresa->nombre}")
            ->greeting("Hola {$notifiable->name}")
            ->line("**URGENTE:** El certificado digital de la empresa **{$empresa->nombre}** (RUC: {$empresa->ruc}) está **VENCIDO hace {$this->diasVencido} día(s)**.")
            ->line("Fecha de vencimiento: {$this->credencial->certificado_vence_en?->format('d/m/Y')}")
            ->line('')
            ->line('**CONSECUENCIA:** El sistema **no puede emitir ningún comprobante electrónico** (facturas, boletas, notas de crédito) hasta que se suba un certificado válido.')
            ->line('')
            ->line('**Acción inmediata requerida:**')
            ->line('1. Renovar el certificado digital ante la entidad certificadora (INDECOPI)')
            ->line('2. Subir el nuevo archivo .pfx en Configuración → Facturación → Credenciales SUNAT')
            ->line('3. Ingresar la contraseña del certificado')
            ->line('')
            ->line('Esta alerta se repite diariamente hasta que se resuelva.')
            ->action('Ir a Credenciales SUNAT', url('/facturacion/credenciales'))
            ->salutation('Equipo de Facturación Electrónica');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'certificado_vencido',
            'empresa_id' => $this->credencial->empresa_id,
            'empresa_nombre' => $this->credencial->empresa->nombre,
            'ruc' => $this->credencial->empresa->ruc,
            'dias_vencido' => $this->diasVencido,
            'vencio_en' => $this->credencial->certificado_vence_en?->toDateString(),
            'mensaje' => "CERTIFICADO VENCIDO hace {$this->diasVencido} día(s). Emisión BLOQUEADA. Suba nuevo .pfx urgente.",
        ];
    }
}