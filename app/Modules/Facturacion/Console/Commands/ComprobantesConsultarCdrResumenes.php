<?php

namespace App\Modules\Facturacion\Console\Commands;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Services\ComprobanteSunatService;
use App\Modules\Facturacion\Services\ConfiguracionFacturacionService;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consulta el CDR de resúmenes diarios y comunicaciones de baja pendientes.
 *
 * Se ejecuta periódicamente (ej. cada hora) para procesar tickets
 * que SUNAT respondió de forma asíncrona.
 */
class ComprobantesConsultarCdrResumenes extends Command
{
    protected $signature = 'comprobantes:consultar-cdr-resumenes
                            {--lote=50 : Máximo tickets a consultar por ejecución}';

    protected $description = 'Consulta CDR de resúmenes diarios y bajas enviadas a SUNAT (tickets pendientes)';

    public function handle(
        ComprobanteSunatService $sunat,
        ConfiguracionFacturacionService $configuracion,
    ): int {
        $lote = (int) $this->option('lote');

        // Buscar comprobantes con ticket de resumen/baja pendiente de CDR
        $pendientes = Comprobante::query()
            ->whereNotNull('resumen_diario_ticket')
            ->whereNull('resumen_diario_aceptado_at')
            ->whereHas('empresa.configuracionFacturacion', fn ($q) => $q->where('activo', true))
            ->whereHas('empresa.credencialSunat', fn ($q) => $q->whereNotNull('certificado_path'))
            ->with(['empresa.configuracionFacturacion', 'empresa.credencialSunat'])
            ->orderBy('resumen_diario_enviado_at')
            ->limit($lote)
            ->get();

        if ($pendientes->isEmpty()) {
            $this->info('No hay tickets de resumen/baja pendientes de CDR.');
            return Command::SUCCESS;
        }

        $this->info("Consultando CDR de {$pendientes->count()} ticket(s)...");

        $procesados = 0;
        $aceptados = 0;
        $observados = 0;
        $rechazados = 0;
        $errores = 0;

        foreach ($pendientes as $comprobante) {
            try {
                $empresa = $comprobante->empresa;
                $config = $configuracion->resolverPara($empresa);
                $credencial = $empresa->credencialSunat;

                if (! $credencial || ! $credencial->estaCompleta() || $credencial->estaVencido()) {
                    $this->warn("Saltando {$comprobante->numero}: credencial inválida");
                    $errores++;
                    continue;
                }

                $respuesta = $sunat->consultarCdrBajaResumen($comprobante->resumen_diario_ticket, $credencial, $config->modo);

                if ($respuesta['exito']) {
                    $sunat->aplicarRespuestaBaja($comprobante, $respuesta);
                    $procesados++;

                    if ($respuesta['cdr_codigo'] === '0') {
                        $aceptados++;
                        $this->line("  ✅ {$comprobante->numero}: CDR aceptado");
                    } elseif (str_starts_with((string) $respuesta['cdr_codigo'], '2')) {
                        $observados++;
                        $this->warn("  ⚠️ {$comprobante->numero}: CDR observado ({$respuesta['cdr_codigo']})");
                    } else {
                        $rechazados++;
                        $this->error("  ❌ {$comprobante->numero}: CDR rechazado ({$respuesta['cdr_codigo']})");
                    }
                } else {
                    // CDR aún no listo (ticket en proceso)
                    $this->line("  ⏳ {$comprobante->numero}: CDR aún no disponible");
                }

            } catch (\Throwable $e) {
                $errores++;
                Log::error('Error consultando CDR resumen/baja', [
                    'comprobante' => $comprobante->numero ?? 'N/A',
                    'error' => $e->getMessage(),
                ]);
                $this->error("  ✗ Error: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Procesados: {$procesados} | Aceptados: {$aceptados} | Observados: {$observados} | Rechazados: {$rechazados} | Errores: {$errores}");

        return Command::SUCCESS;
    }
}