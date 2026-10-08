<?php

namespace App\Modules\Facturacion\Jobs;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ComprobanteEnvio;
use App\Modules\Facturacion\Services\ComprobanteSunatService;
use App\Modules\Facturacion\Services\ConfiguracionFacturacionService;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job para enviar un comprobante a SUNAT de forma asíncrona.
 *
 * - Se despacha tras `ComprobanteService::emitirDesdeCredito()` (estado = generado).
 * - Reintentos con backoff exponencial: 1m, 5m, 15m, 1h, 6h.
 * - Conserva el correlativo aunque falle (nunca se libera).
 * - Actualiza `comprobante_envios` con el detalle de cada intento.
 */
final class EnviarComprobanteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;
    public int $backoff = 60; // base para backoff exponencial

    public function __construct(
        public readonly int $comprobanteId,
    ) {}

    public function handle(
        ComprobanteSunatService $sunat,
        ConfiguracionFacturacionService $configuracion,
    ): void {
        $comprobante = Comprobante::with(['empresa', 'serieComprobante', 'detalles'])
            ->findOrFail($this->comprobanteId);

        // Solo procesar si está en estado enviable
        if (! $comprobante->estado->enviable()) {
            Log::warning('Job omitido: comprobante no enviable', [
                'comprobante' => $comprobante->numero,
                'estado' => $comprobante->estado->value,
            ]);
            return;
        }

        // Verificar certificado
        $credencial = $comprobante->empresa->credencialSunat;
        if (! $credencial || ! $credencial->estaCompleta()) {
            $this->registrarError($comprobante, 'Credenciales SUNAT incompletas');
            $this->marcarError($comprobante, $sunat, 'Credenciales SUNAT incompletas');
            return;
        }

        if ($credencial->estaVencido()) {
            $this->registrarError($comprobante, 'Certificado digital vencido');
            $this->marcarError($comprobante, $sunat, 'Certificado digital vencido');
            return;
        }

        $cfg = $configuracion->resolverPara($comprobante->empresa);

        try {
            DB::beginTransaction();

            // Marcar como enviado
            $comprobante->update([
                'estado' => EstadoComprobante::Enviado->value,
                'enviado_at' => now(),
            ]);

            // Enviar a SUNAT
            $respuesta = $sunat->emitir($comprobante, $credencial, $cfg->modo);

            // Registrar envío en bitácora
            $this->registrarEnvio($comprobante, $respuesta);

            // Aplicar respuesta al comprobante
            $sunat->aplicarRespuesta($comprobante, $respuesta);

            DB::commit();

            Log::info('Comprobante enviado a SUNAT', [
                'comprobante' => $comprobante->numero,
                'estado' => $comprobante->estado->value,
                'cdr_codigo' => $respuesta['cdr_codigo'] ?? null,
            ]);

            // Disparar evento/notificación según estado final
            $this->notificarResultado($comprobante, $respuesta);

        } catch (Throwable $e) {
            DB::rollBack();

            $error = $e->getMessage();
            Log::error('Error en EnviarComprobanteJob', [
                'comprobante' => $comprobante->numero,
                'error' => $error,
                'intento' => $this->attempts(),
            ]);

            $this->registrarError($comprobante, $error);
            $this->marcarError($comprobante, $sunat, $error);

            // Re-lanzar para que el queue manager haga el reintento con backoff
            throw $e;
        }
    }

    /**
     * Backoff exponencial: 1m, 5m, 15m, 1h, 6h
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 21600];
    }

    /**
     * Qué hacer cuando se agotan los reintentos.
     */
    public function failed(Throwable $e): void
    {
        $comprobante = Comprobante::find($this->comprobanteId);

        if ($comprobante) {
            $comprobante->update([
                'estado' => EstadoComprobante::Error->value,
                'cdr_descripcion' => 'Agotados reintentos: '.$e->getMessage(),
            ]);

            Log::critical('Comprobante en error permanente tras agotar reintentos', [
                'comprobante' => $comprobante->numero,
                'error' => $e->getMessage(),
            ]);

            // Notificar a administradores
            $this->alertarAdministradores($comprobante, $e->getMessage());
        }
    }

    private function registrarEnvio(Comprobante $comprobante, array $respuesta): void
    {
        ComprobanteEnvio::create([
            'comprobante_id' => $comprobante->id,
            'empresa_id' => $comprobante->empresa_id,
            'intento' => $this->attempts(),
            'estado' => $respuesta['exito'] ? 'enviado' : 'error',
            'request_xml' => $respuesta['xml'] ?? null,
            'cdr_codigo' => $respuesta['cdr_codigo'] ?? null,
            'cdr_descripcion' => $respuesta['cdr_descripcion'] ?? null,
            'cdr_xml' => $respuesta['cdr_xml'] ?? null,
            'excepcion' => $respuesta['error'] ?? null,
            'duracion_ms' => null, // Se podría medir con microtime
        ]);
    }

    private function registrarError(Comprobante $comprobante, string $error): void
    {
        ComprobanteEnvio::create([
            'comprobante_id' => $comprobante->id,
            'empresa_id' => $comprobante->empresa_id,
            'intento' => $this->attempts(),
            'estado' => 'error',
            'cdr_descripcion' => $error,
            'excepcion' => $error,
        ]);
    }

    private function marcarError(Comprobante $comprobante, ComprobanteSunatService $sunat, string $error): void
    {
        $sunat->marcarError($comprobante, $error);
    }

    private function notificarResultado(Comprobante $comprobante, array $respuesta): void
    {
        // Los eventos se disparan desde el Observer o en el servicio
        // Aquí solo loggeamos; el evento ComprobanteActualizado se dispara
        // automáticamente por el update en aplicarRespuesta()
    }

    private function alertarAdministradores(Comprobante $comprobante, string $error): void
    {
        // TODO: Disparar notificación a administradores (email, Slack, etc.)
        Log::critical('ALERTA: Comprobante requiere intervención manual', [
            'comprobante' => $comprobante->numero,
            'empresa' => $comprobante->empresa_id,
            'error' => $error,
        ]);
    }
}