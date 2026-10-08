<?php

namespace App\Modules\Facturacion\Console\Commands;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Jobs\EnviarComprobanteJob;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reintenta el envío de comprobantes que quedaron en error o generado huérfano.
 *
 * Se ejecuta cada 15 minutos vía scheduler.
 * Procesa en lotes para no saturar la cola.
 */
class ComprobantesReintentarEnvio extends Command
{
    protected $signature = 'comprobantes:reintentar-envio {--lote=50 : Cantidad máxima de comprobantes por ejecución} {--estados=error,generado : Estados a reintentar (coma-separados)}';

    protected $description = 'Reintenta envío de comprobantes a SUNAT que fallaron o quedaron pendientes';

    public function handle(): int
    {
        $lote = (int) $this->option('lote');
        $estados = array_map('trim', explode(',', $this->option('estados')));

        $comprobantes = Comprobante::query()
            ->whereIn('estado', $estados)
            ->whereHas('empresa.credencialSunat', fn ($q) => $q->whereNotNull('certificado_path'))
            ->with(['empresa.credencialSunat'])
            ->orderBy('enviado_at')
            ->limit($lote)
            ->get();

        if ($comprobantes->isEmpty()) {
            $this->info('No hay comprobantes para reintentar.');
            return Command::SUCCESS;
        }

        $this->info("Reintentando {$comprobantes->count()} comprobante(s)...");

        $procesados = 0;
        $fallidos = 0;

        foreach ($comprobantes as $comprobante) {
            try {
                // Verificar que la credencial sigue vigente
                $credencial = $comprobante->empresa->credencialSunat;
                if (! $credencial || ! $credencial->estaCompleta() || $credencial->estaVencido()) {
                    $this->warn("Saltando {$comprobante->numero}: credencial inválida o vencida");
                    $fallidos++;
                    continue;
                }

                // Despachar job (no ejecutar inline para respetar backoff y timeouts)
                EnviarComprobanteJob::dispatch($comprobante->id);
                $procesados++;

                $intento = $comprobante->envios->count() + 1;
                $this->line("  → Despachado: {$comprobante->numero} (intento {$intento})");

            } catch (\Throwable $e) {
                $fallidos++;
                Log::error('Error despachando reintento', [
                    'comprobante' => $comprobante->numero,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  ✗ Error despachando {$comprobante->numero}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Despachados: {$procesados} | Omitidos/Error: {$fallidos}");

        return Command::SUCCESS;
    }
}