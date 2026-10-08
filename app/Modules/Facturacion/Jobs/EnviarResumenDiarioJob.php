<?php

namespace App\Modules\Facturacion\Jobs;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Services\ComprobanteSunatService;
use App\Modules\Facturacion\Services\ConfiguracionFacturacionService;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job para enviar el resumen diario de boletas a SUNAT.
 *
 * Se ejecuta vía comando programado a las 23:00 (configurable).
 * Agrupa todas las boletas aceptadas del día y envía el resumen.
 * Debe ser idempotente por fecha: si ya se envió, no duplicar.
 */
final class EnviarResumenDiarioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;
    public int $backoff = 300;

    public function __construct(
        public readonly string $fecha, // Y-m-d
        public readonly int $empresaId,
        public readonly ?int $agenciaId = null,
    ) {}

    public function handle(
        ComprobanteSunatService $sunat,
        ConfiguracionFacturacionService $configuracion,
    ): void {
        $fecha = \Carbon\Carbon::parse($this->fecha);
        $empresaId = $this->empresaId;
        $agenciaId = $this->agenciaId;

        // 1. Obtener boletas del día que necesitan ir en el resumen
        $boletas = $this->obtenerBoletasParaResumen($fecha, $empresaId, $agenciaId);

        if ($boletas->isEmpty()) {
            Log::info('Resumen diario: no hay boletas para enviar', [
                'fecha' => $fecha->toDateString(),
                'empresa' => $empresaId,
            ]);
            return;
        }

        // 2. Verificar que la empresa tiene credenciales
        $empresa = $boletas->first()->empresa;
        $credencial = $empresa->credencialSunat;

        if (! $credencial || ! $credencial->estaCompleta()) {
            Log::error('Resumen diario: credenciales incompletas', [
                'empresa' => $empresaId,
            ]);
            return;
        }

        if ($credencial->estaVencido()) {
            Log::error('Resumen diario: certificado vencido', [
                'empresa' => $empresaId,
            ]);
            return;
        }

        $cfg = $configuracion->resolverPara($empresa);

        try {
            DB::beginTransaction();

            // 3. Enviar resumen diario
            $respuesta = $sunat->enviarResumenDiario($boletas->all(), $credencial, $cfg->modo);

            if ($respuesta['exito'] && $respuesta['ticket']) {
                // El resumen se envió, pero el CDR viene asíncrono (ticket)
                // Guardamos el ticket para consultar luego
                $this->registrarTicketResumen($fecha, $empresaId, $agenciaId, $respuesta['ticket'], $boletas);

                Log::info('Resumen diario enviado a SUNAT (ticket generado)', [
                    'fecha' => $fecha->toDateString(),
                    'empresa' => $empresaId,
                    'ticket' => $respuesta['ticket'],
                    'boletas' => $boletas->count(),
                ]);
            } elseif ($respuesta['exito'] && $respuesta['cdr_codigo']) {
                // Respuesta síncrona (beta a veces devuelve CDR directo)
                $this->procesarCdrResumen($respuesta, $boletas);

                Log::info('Resumen diario aceptado directamente', [
                    'fecha' => $fecha->toDateString(),
                    'empresa' => $empresaId,
                    'cdr_codigo' => $respuesta['cdr_codigo'],
                ]);
            } else {
                throw new \RuntimeException('Error enviando resumen: '.($respuesta['error'] ?? 'Desconocido'));
            }

            DB::commit();

        } catch (Throwable $e) {
            DB::rollBack();

            Log::error('Error en EnviarResumenDiarioJob', [
                'fecha' => $fecha->toDateString(),
                'empresa' => $empresaId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtiene las boletas aceptadas del día que aún no están en un resumen enviado.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Comprobante>
     */
    private function obtenerBoletasParaResumen(\Carbon\Carbon $fecha, int $empresaId, ?int $agenciaId): \Illuminate\Database\Eloquent\Collection
    {
        return Comprobante::query()
            ->where('empresa_id', $empresaId)
            ->when($agenciaId !== null, fn ($q) => $q->where('agencia_id', $agenciaId))
            ->where('tipo_documento', TipoComprobante::Boleta->value)
            ->where('fecha_emision', $fecha->toDateString())
            ->whereIn('estado', [EstadoComprobante::Aceptado->value, EstadoComprobante::Observado->value])
            ->whereNull('resumen_diario_enviado_at') // Campo que hay que agregar a la migración
            ->with(['empresa'])
            ->get();
    }

    private function registrarTicketResumen(\Carbon\Carbon $fecha, int $empresaId, ?int $agenciaId, string $ticket, \Illuminate\Database\Eloquent\Collection $boletas): void
    {
        // Marcar boletas como incluidas en resumen (pendiente de CDR)
        foreach ($boletas as $boleta) {
            $boleta->update([
                'resumen_diario_ticket' => $ticket,
                'resumen_diario_enviado_at' => now(),
            ]);
        }

        // TODO: Guardar ticket en tabla resumenes_diarios para consultar CDR después
    }

    private function procesarCdrResumen(array $respuesta, \Illuminate\Database\Eloquent\Collection $boletas): void
    {
        if ($respuesta['cdr_codigo'] === '0') {
            foreach ($boletas as $boleta) {
                $boleta->update([
                    'resumen_diario_aceptado_at' => now(),
                    'resumen_diario_cdr_codigo' => $respuesta['cdr_codigo'],
                ]);
            }
        } else {
            // El resumen fue rechazado/observado - loggear para revisión manual
            Log::warning('Resumen diario con observaciones/rechazo', [
                'cdr_codigo' => $respuesta['cdr_codigo'],
                'cdr_descripcion' => $respuesta['cdr_descripcion'],
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::critical('Resumen diario falló permanentemente', [
            'fecha' => $this->fecha,
            'empresa' => $this->empresaId,
            'error' => $e->getMessage(),
        ]);
    }
}