<?php

namespace App\Modules\Facturacion\Console\Commands;

use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Facturacion\Notifications\CertificadoPorVencer;
use App\Modules\Facturacion\Notifications\CertificadoVencido;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Alerta diaria de certificados por vencer o vencidos.
 *
 * Se ejecuta diario vía scheduler.
 * Notifica a administradores 60/30/15 días antes y el día del vencimiento.
 */
class FacturacionAlertaCertificado extends Command
{
    protected $signature = 'facturacion:alerta-certificado';

    protected $description = 'Envía alertas de certificados digitales por vencer o vencidos';

    public function handle(): int
    {
        $hoy = Carbon::today();

        $credenciales = CredencialSunat::query()
            ->whereNotNull('certificado_vence_en')
            ->with('empresa')
            ->get();

        if ($credenciales->isEmpty()) {
            $this->info('No hay certificados registrados.');
            return Command::SUCCESS;
        }

        $alertas = 0;
        $vencidos = 0;

        foreach ($credenciales as $credencial) {
            if (! $credencial->certificado_vence_en) {
                continue;
            }

            $vence = Carbon::parse($credencial->certificado_vence_en);
            $dias = $hoy->diffInDays($vence, false); // false = negativo si ya pasó

            // Ya vencido
            if ($dias < 0) {
                $this->alertaVencido($credencial, abs($dias));
                $vencidos++;
                continue;
            }

            // Alertas en hitos: 60, 30, 15, 7, 3, 1, 0 días
            $hitos = [60, 30, 15, 7, 3, 1, 0];

            if (in_array($dias, $hitos, true)) {
                $this->alertaPorVencer($credencial, $dias);
                $alertas++;
            }
        }

        $this->info("Alertas enviadas: {$alertas} por vencer | {$vencidos} vencidos");

        return Command::SUCCESS;
    }

    private function alertaPorVencer(CredencialSunat $credencial, int $dias): void
    {
        $empresa = $credencial->empresa;

        Log::warning('Certificado por vencer', [
            'empresa' => $empresa->id,
            'empresa_nombre' => $empresa->nombre,
            'ruc' => $empresa->ruc,
            'dias_restantes' => $dias,
            'vence_en' => $credencial->certificado_vence_en?->toDateString(),
        ]);

        // Notificar a administradores de la empresa
        $admins = $empresa->usuarios()
            ->whereHas('roles', fn ($q) => $q->where('name', 'administrador_general'))
            ->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new CertificadoPorVencer($credencial, $dias));
        }

        $this->line("  ⚠️ {$empresa->nombre} (RUC: {$empresa->ruc}): vence en {$dias} día(s)");
    }

    private function alertaVencido(CredencialSunat $credencial, int $diasVencido): void
    {
        $empresa = $credencial->empresa;

        Log::critical('Certificado VENCIDO - emisión bloqueada', [
            'empresa' => $empresa->id,
            'empresa_nombre' => $empresa->nombre,
            'ruc' => $empresa->ruc,
            'dias_vencido' => $diasVencido,
            'vencio_en' => $credencial->certificado_vence_en?->toDateString(),
        ]);

        // Notificar a administradores (urgente)
        $admins = $empresa->usuarios()
            ->whereHas('roles', fn ($q) => $q->where('name', 'administrador_general'))
            ->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new CertificadoVencido($credencial, $diasVencido));
        }

        // También notificar a sistemas si existe
        $sistemas = $empresa->usuarios()
            ->whereHas('roles', fn ($q) => $q->where('name', 'sistemas'))
            ->get();

        if ($sistemas->isNotEmpty()) {
            Notification::send($sistemas, new CertificadoVencido($credencial, $diasVencido));
        }

        $this->error("  ❌ {$empresa->nombre} (RUC: {$empresa->ruc}): VENCIDO hace {$diasVencido} día(s) - EMISIÓN BLOQUEADA");
    }
}