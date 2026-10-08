<?php

namespace App\Modules\Facturacion\Console\Commands;

use App\Modules\Facturacion\Jobs\EnviarResumenDiarioJob;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Envía el resumen diario de boletas a SUNAT.
 *
 * Se ejecuta a las 23:00 (configurable) vía scheduler.
 * Procesa cada empresa que tenga facturación activa y boletas pendientes.
 * Debe ser idempotente: si ya se envió el resumen de esa fecha, no duplicar.
 */
class ComprobantesEnviarResumenDiario extends Command
{
    protected $signature = 'comprobantes:enviar-resumen-diario {--fecha= : Fecha del resumen (Y-m-d). Por defecto ayer.} {--empresa= : ID de empresa específica. Por defecto todas activas.}';

    protected $description = 'Envía el resumen diario de boletas a SUNAT';

    public function handle(): int
    {
        $fechaStr = $this->option('fecha') ?: Carbon::yesterday()->toDateString();
        $empresaId = $this->option('empresa') ? (int) $this->option('empresa') : null;

        try {
            $fecha = Carbon::parse($fechaStr);
        } catch (\Throwable) {
            $this->error("Fecha inválida: {$fechaStr}. Use Y-m-d.");
            return Command::FAILURE;
        }

        $this->info("Enviando resumen diario de {$fecha->format('d/m/Y')}...");

        // Empresas con facturación activa y credenciales
        $query = ConfiguracionFacturacion::query()
            ->where('activo', true)
            ->whereHas('empresa.credencialSunat', fn ($q) => $q->whereNotNull('certificado_path'));

        if ($empresaId !== null) {
            $query->where('empresa_id', $empresaId);
        }

        $configuraciones = $query->with('empresa.credencialSunat')->get();

        if ($configuraciones->isEmpty()) {
            $this->warn('No hay empresas con facturación activa y credenciales válidas.');
            return Command::SUCCESS;
        }

        $enviados = 0;
        $sinBoletas = 0;
        $errores = 0;

        foreach ($configuraciones as $config) {
            $empresa = $config->empresa;
            $credencial = $empresa->credencialSunat;

            if (! $credencial || ! $credencial->estaCompleta()) {
                $this->warn("Saltando empresa {$empresa->id} ({$empresa->nombre}): credenciales incompletas");
                $errores++;
                continue;
            }

            if ($credencial->estaVencido()) {
                $this->warn("Saltando empresa {$empresa->id} ({$empresa->nombre}): certificado vencido");
                $errores++;
                continue;
            }

            // Verificar si ya se envió resumen para esta fecha/empresa
            $yaEnviado = Comprobante::query()
                ->where('empresa_id', $empresa->id)
                ->where('tipo_documento', TipoComprobante::Boleta->value)
                ->where('fecha_emision', $fecha->toDateString())
                ->whereNotNull('resumen_diario_enviado_at')
                ->exists();

            if ($yaEnviado) {
                $this->line("  → Empresa {$empresa->id}: resumen ya enviado para {$fecha->format('d/m/Y')}");
                continue;
            }

            // Contar boletas pendientes de resumen
            $pendientes = Comprobante::query()
                ->where('empresa_id', $empresa->id)
                ->where('tipo_documento', TipoComprobante::Boleta->value)
                ->where('fecha_emision', $fecha->toDateString())
                ->whereIn('estado', [EstadoComprobante::Aceptado->value, EstadoComprobante::Observado->value])
                ->whereNull('resumen_diario_enviado_at')
                ->count();

            if ($pendientes === 0) {
                $this->line("  → Empresa {$empresa->id}: sin boletas pendientes");
                $sinBoletas++;
                continue;
            }

            // Despachar job para esta empresa
            EnviarResumenDiarioJob::dispatch($fecha->toDateString(), $empresa->id);
            $enviados++;

            $this->line("  → Despachado resumen: Empresa {$empresa->id} ({$pendientes} boletas)");
        }

        $this->newLine();
        $this->info("Resumen diario: {$enviados} despachados | {$sinBoletas} sin boletas | {$errores} errores");

        return Command::SUCCESS;
    }
}